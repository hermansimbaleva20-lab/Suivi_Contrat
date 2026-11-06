<?php

namespace Tests\Feature;

use App\Models\OrganizationalUnit;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Ctx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Socle organisationnel (TDR §5, §16 phase 2) : référentiel administrable, isolation projet/pool, audit avant/après. */
class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private Project $predire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        $this->predire = Project::create(['code' => 'PREDIRE', 'name' => 'PREDIRE']);
        foreach ([$this->prise, $this->predire] as $p) {
            ProjectPhase::create(['project_id' => $p->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        }
    }

    private function user(Project $p, array $attrs = []): User
    {
        $u = new User();
        $u->forceFill(array_merge([
            'project_id' => $p->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false,
        ], $attrs))->save();
        return $u;
    }

    private function as(User $u, string $project = 'PRISE'): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => $project, 'Accept' => 'application/json']);
    }

    private function pool(Project $p, string $code): OrganizationalUnit
    {
        return OrganizationalUnit::create(['project_id' => $p->id, 'type' => 'POOL', 'code' => $code, 'name' => "Pool $code", 'is_active' => true]);
    }

    public function test_un_compte_sans_habilitation_ne_peut_pas_administrer_le_referentiel(): void
    {
        $agent = $this->user($this->prise);

        $this->as($agent)->getJson('/api/organization')->assertForbidden();
        $this->as($agent)->postJson('/api/organization/units', ['type' => 'POOL', 'code' => 'P1', 'name' => 'Pool 1'])->assertForbidden();
        $this->assertSame(0, OrganizationalUnit::where('code', 'P1')->count());
    }

    public function test_administrateur_cree_un_pool_et_l_audit_conserve_les_valeurs(): void
    {
        $admin = $this->user($this->prise, ['can_manage_settings' => true]);

        $this->as($admin)->postJson('/api/organization/units', ['type' => 'POOL', 'code' => 'kananga', 'name' => 'Pool Kananga'])
            ->assertCreated()->assertJsonPath('unit.code', 'KANANGA');
        $this->as($admin)->postJson('/api/organization/units', ['type' => 'POOL', 'code' => 'KANANGA', 'name' => 'Doublon'])
            ->assertStatus(422);

        $log = DB::table('audit_logs')->where('action', 'CREATION_UNITE')->first();
        $this->assertNotNull($log);
        $this->assertSame('Pool Kananga', json_decode($log->new_values, true)['name']);
    }

    public function test_isolation_entre_projets(): void
    {
        $poolPredire = $this->pool($this->predire, 'X');
        $adminPrise = $this->user($this->prise, ['can_manage_settings' => true, 'can_manage_users' => true]);
        $agentPrise = $this->user($this->prise);

        // Une unité d'un autre projet est introuvable, et ne peut pas être affectée.
        $this->as($adminPrise)->putJson("/api/organization/units/{$poolPredire->id}", ['type' => 'POOL', 'code' => 'X', 'name' => 'Pirate'])->assertNotFound();
        $superPrise = $this->user($this->prise, ['is_super_admin' => true, 'role' => 'ADMINISTRATEUR']);
        $this->as($superPrise)->putJson("/api/users/{$agentPrise->id}/assignment", ['organizational_unit_id' => $poolPredire->id])->assertStatus(422);
        // L'en-tête X-Project ne permet pas de changer de projet.
        $this->as($adminPrise, 'PREDIRE')->getJson('/api/organization')->assertForbidden();
        $this->assertSame('Pool X', $poolPredire->fresh()->name);
    }

    public function test_unite_utilisee_desactivee_mais_pas_supprimee(): void
    {
        $admin = $this->user($this->prise, ['can_manage_settings' => true]);
        $pool = $this->pool($this->prise, 'P1');
        $this->user($this->prise, ['organizational_unit_id' => $pool->id]);

        $this->as($admin)->deleteJson("/api/organization/units/{$pool->id}")->assertStatus(422);
        $this->as($admin)->putJson("/api/organization/units/{$pool->id}/toggle")->assertOk()->assertJsonPath('unit.isActive', false);
        $this->assertDatabaseHas('organizational_units', ['id' => $pool->id]);
    }

    public function test_inscription_impose_unite_et_poste_des_qu_ils_existent(): void
    {
        $pool = $this->pool($this->prise, 'P1');
        $poste = \App\Models\Position::create(['project_id' => $this->prise->id, 'name' => 'Logisticien', 'is_active' => true]);
        $base = ['project' => 'PRISE', 'name' => 'Nouvel agent', 'email' => 'nouveau@example.test', 'password' => 'Secret-2026', 'password_confirmation' => 'Secret-2026'];

        $this->postJson('/api/auth/register', $base)->assertStatus(422);
        $this->getJson('/api/auth/registration-options?project=PRISE')->assertOk()->assertJsonCount(1, 'units')->assertJsonPath('units.0.name', 'Pool P1');
        $this->postJson('/api/auth/register', $base + ['organizational_unit_id' => $pool->id, 'position_id' => $poste->id])->assertCreated();

        $u = User::where('email', 'nouveau@example.test')->first();
        $this->assertSame($pool->id, (int) $u->organizational_unit_id);
        $this->assertSame('Logisticien', $u->project_position);
        $this->assertTrue((bool) $u->is_pending);
    }

    public function test_perimetre_de_lecture_et_d_ecriture_par_unite(): void
    {
        $cn = OrganizationalUnit::where('project_id', $this->prise->id)->where('type', 'COORDINATION')->first()
            ?? OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => 'COORDINATION', 'code' => 'CN', 'name' => 'CN', 'is_active' => true]);
        $p1 = $this->pool($this->prise, 'P1');
        $p2 = $this->pool($this->prise, 'P2');

        $agentP1 = $this->user($this->prise, ['organizational_unit_id' => $p1->id]);
        $coordination = $this->user($this->prise, ['organizational_unit_id' => $cn->id]);
        $vueNationale = $this->user($this->prise, ['organizational_unit_id' => $cn->id, 'can_view_pools' => true]);
        $admin = $this->user($this->prise, ['role' => 'ADMINISTRATEUR', 'organizational_unit_id' => $cn->id]);
        $super = $this->user($this->prise, ['is_super_admin' => true, 'role' => 'ADMINISTRATEUR', 'organizational_unit_id' => $cn->id]);
        $nonAffecte = $this->user($this->prise);

        $this->assertSame([$p1->id], Ctx::readableUnitIds($agentP1));
        $this->assertTrue(Ctx::canWriteUnit($p1->id, $agentP1));
        $this->assertFalse(Ctx::canWriteUnit($p2->id, $agentP1));

        // Chaque unité est autonome : la coordination nationale ne voit que ses propres opérations.
        $this->assertSame([$cn->id], Ctx::readableUnitIds($coordination));
        $this->assertTrue(Ctx::canWriteUnit($cn->id, $coordination));

        // Vue nationale désignée par le super-admin : lecture de tous les pools, sans droit d'écriture.
        $this->assertNull(Ctx::readableUnitIds($vueNationale));
        $this->assertFalse(Ctx::canWriteUnit($p1->id, $vueNationale));
        $this->assertTrue(Ctx::canWriteUnit($cn->id, $vueNationale));

        // L'administrateur a toutes les habilitations, mais dans son unité seulement ; le super-admin partout.
        $this->assertSame([$cn->id], Ctx::readableUnitIds($admin));
        $this->assertFalse(Ctx::canWriteUnit($p2->id, $admin));
        $this->assertNull(Ctx::readableUnitIds($super));
        $this->assertTrue(Ctx::canWriteUnit($p2->id, $super));

        // Compte non affecté : aucune opération d'unité visible ni modifiable.
        $this->assertSame([], Ctx::readableUnitIds($nonAffecte));
        $this->assertFalse(Ctx::canWriteUnit($p1->id, $nonAffecte));
    }

    public function test_habilitations_et_role_octroyes_par_le_super_admin(): void
    {
        $cn = OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => 'COORDINATION', 'code' => 'CN', 'name' => 'CN', 'is_active' => true]);
        $p1 = $this->pool($this->prise, 'P1');
        $poste = \App\Models\Position::create(['project_id' => $this->prise->id, 'organizational_unit_id' => $p1->id, 'name' => 'C.A P1', 'is_active' => true]);
        $agent = $this->user($this->prise, ['organizational_unit_id' => $p1->id, 'position_id' => $poste->id, 'project_position' => 'C.A P1']);
        $gestionnaire = $this->user($this->prise, ['role' => 'ADMINISTRATEUR', 'organizational_unit_id' => $cn->id]);
        $super = $this->user($this->prise, ['is_super_admin' => true, 'role' => 'ADMINISTRATEUR', 'organizational_unit_id' => $cn->id]);
        $form = ['role' => 'ADMINISTRATEUR', 'can_view_stock' => true, 'can_view_pools' => true];

        // Le poste occupé tient lieu de rôle affiché.
        $this->as($agent)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.title', 'C.A P1');
        // Un administrateur ne voit ni ne gère les comptes d'une autre unité, et n'octroie pas d'habilitations.
        $this->assertNotContains($agent->id, array_column($this->as($gestionnaire)->getJson('/api/users')->assertOk()->json('users'), 'id'));
        $this->as($gestionnaire)->putJson("/api/users/{$agent->id}/permissions", $form)->assertForbidden();
        $this->as($super)->putJson("/api/users/{$agent->id}/permissions", ['can_view_stock' => true])->assertStatus(422);
        $this->as($super)->putJson("/api/users/{$agent->id}/permissions", $form)->assertOk();

        $agent->refresh();
        $this->assertSame('ADMINISTRATEUR', $agent->role);
        $this->assertTrue($agent->can_view_pools);
        $this->assertNull(Ctx::readableUnitIds($agent));
        $log = DB::table('audit_logs')->where('action', 'HABILITATIONS')->first();
        $this->assertFalse(json_decode($log->old_values, true)['can_view_pools']);
        $this->assertTrue(json_decode($log->new_values, true)['can_view_pools']);
    }

    public function test_autorisation_logistique_une_qualite_par_poste(): void
    {
        $cn = OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => 'COORDINATION', 'code' => 'CN', 'name' => 'CN', 'is_active' => true]);
        $p1 = $this->pool($this->prise, 'P1');
        $super = $this->user($this->prise, ['is_super_admin' => true, 'role' => 'ADMINISTRATEUR', 'organizational_unit_id' => $cn->id]);
        $ca = $this->user($this->prise, ['organizational_unit_id' => $p1->id]);
        $hraf = $this->user($this->prise, ['organizational_unit_id' => $cn->id]);
        $form = fn (?string $authority) => ['role' => 'UTILISATEUR', 'logistics_authority' => $authority];

        // Le C.A en antenne ; le HRAF ou le comptable à la coordination.
        $this->as($super)->putJson("/api/users/{$ca->id}/permissions", $form('HRAF'))->assertStatus(422)->assertJsonValidationErrors('logistics_authority');
        $this->as($super)->putJson("/api/users/{$hraf->id}/permissions", $form('CA'))->assertStatus(422)->assertJsonValidationErrors('logistics_authority');
        $this->as($super)->putJson("/api/users/{$ca->id}/permissions", $form('CA'))->assertOk();
        $this->as($super)->putJson("/api/users/{$hraf->id}/permissions", $form('HRAF'))->assertOk();
        $this->assertTrue((bool) $ca->fresh()->can_authorize_fleet);
        $this->assertSame('HRAF', $hraf->fresh()->logistics_authority);

        // Décocher le poste retire l'autorisation.
        $this->as($super)->putJson("/api/users/{$hraf->id}/permissions", $form(null))->assertOk();
        $this->assertFalse((bool) $hraf->fresh()->can_authorize_fleet);
        $this->assertNull($hraf->fresh()->logistics_authority);

        // La qualité ne suit pas un compte déplacé vers un autre type d'unité, mais reste d'une antenne à l'autre.
        $p2 = $this->pool($this->prise, 'P2');
        $this->as($super)->putJson("/api/users/{$ca->id}/assignment", ['organizational_unit_id' => $p2->id])->assertOk();
        $this->assertSame('CA', $ca->fresh()->logistics_authority);
        $this->as($super)->putJson("/api/users/{$ca->id}/assignment", ['organizational_unit_id' => $cn->id])->assertOk();
        $this->assertNull($ca->fresh()->logistics_authority);
        $this->assertFalse((bool) $ca->fresh()->can_authorize_fleet);
        $this->as($super)->putJson("/api/users/{$ca->id}/permissions", $form(null))->assertOk();
    }
}

