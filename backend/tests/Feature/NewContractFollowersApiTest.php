<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Nouveau contrat : les comptes choisis par le super-admin (chef de cellule, personnel de la cellule) sont notifiés pour en commencer le suivi. */
class NewContractFollowersApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private int $company;
    private int $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $this->company = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'GENERTEL GROUPE', 'created_at' => now(), 'updated_at' => now()]);
        $this->unit = DB::table('organizational_units')->insertGetId(['project_id' => $this->prise->id, 'type' => 'COORDINATION', 'code' => 'CN', 'name' => 'Coordination nationale', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(array $attrs = []): User
    {
        $u = new User();
        $u->forceFill(array_merge(['project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false, 'organizational_unit_id' => $this->unit], $attrs))->save();
        return $u;
    }

    private function as(User $u): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    private function form(array $extra = []): array
    {
        return $extra + ['reference' => '074/PRISE/2026', 'company_id' => $this->company, 'category' => 'TRAVAUX', 'province' => 'Kasaï-Central', 'start_date' => '2026-11-01', 'duration_months' => 12, 'amount' => '100000',
            'initial_financing_rate' => '20', 'guarantee_rate' => '5', 'guarantee_bank_name' => 'Rawbank', 'work_mode' => 'Lourd', 'sites' => [['name' => 'Kananga', 'province' => 'Kasaï-Central']]];
    }

    private function notes(User $u): array
    {
        return DB::table('user_notifications')->where('user_id', $u->id)->where('kind', 'NOUVEAU_CONTRAT')->get()->all();
    }

    public function test_les_comptes_choisis_sont_notifies_du_nouveau_contrat(): void
    {
        $manager = $this->user(['can_manage_contracts' => true]);
        $chef = $this->user(['can_follow_new_contracts' => true]);
        $personnel = $this->user(['can_follow_new_contracts' => true]);
        $autre = $this->user(['can_manage_progress' => true]);
        $super = $this->user(['is_super_admin' => true, 'role' => 'ADMINISTRATEUR']);

        $id = $this->as($manager)->postJson('/api/contracts', $this->form())->assertCreated()->json('id');

        foreach ([$chef, $personnel] as $u) {
            $note = $this->notes($u);
            $this->assertCount(1, $note);
            $this->assertSame("/contracts/{$id}", $note[0]->link);
            $this->assertStringContainsString('074/PRISE/2026', $note[0]->title);
            $this->assertStringContainsString('GENERTEL GROUPE', $note[0]->body);
            $this->assertStringContainsString('avancement physique et financier', $note[0]->body);
            $this->assertNull($note[0]->read_at);
        }
        // Dans leur espace : la cloche de l'en-tête.
        $this->assertSame(1, $this->as($chef)->getJson('/api/notifications')->assertOk()->json('unread'));
        // Ni les autres comptes, ni l'auteur, ni le super-admin dès lors que des comptes sont choisis.
        foreach ([$autre, $manager, $super] as $u) $this->assertSame([], $this->notes($u));

        // Corriger le contrat ne notifie pas une seconde fois.
        $site = DB::table('contract_sites')->where('contract_id', $id)->value('id');
        $this->as($manager)->putJson("/api/contracts/{$id}", ['sites' => [['id' => $site, 'name' => 'Kananga', 'province' => 'Kasaï-Central']]] + $this->form())->assertOk();
        $this->assertCount(1, $this->notes($chef));
    }

    public function test_marches_de_biens_et_services_notifies_mais_pas_les_fournitures_de_bureau(): void
    {
        $manager = $this->user(['can_manage_contracts' => true]);
        $logistique = $this->user(['can_manage_stock' => true]);
        $chef = $this->user(['can_follow_new_contracts' => true]);
        $biens = ['category' => 'BIENS', 'selected_provinces' => ['Kasaï-Central'], 'goods_procedure' => 'BAD', 'goods_origin' => 'LOCAL', 'initial_financing_rate' => '10', 'guarantee_rate' => '10'];

        $this->as($manager)->postJson('/api/contracts', $this->form(['reference' => 'BAD-1'] + $biens))->assertCreated();
        $this->assertCount(1, $this->notes($chef));
        $this->as($manager)->postJson('/api/contracts', $this->form(['reference' => 'SRV-1', 'category' => 'SERVICE', 'selected_provinces' => ['Kasaï-Central']]))->assertCreated();
        $this->assertCount(2, $this->notes($chef));

        // Un contrat de fournitures de bureau ne se suit que par le stock : rien à commencer.
        $this->as($logistique)->postJson('/api/contracts', $this->form(['reference' => 'FB-1', 'category' => 'BIENS', 'selected_provinces' => ['Kasaï-Central'], 'goods_procedure' => 'FOURNITURES_BUREAU']))->assertCreated();
        $this->assertCount(2, $this->notes($chef));
    }

    public function test_sans_compte_choisi_le_super_admin_est_prevenu(): void
    {
        $manager = $this->user(['can_manage_contracts' => true]);
        $super = $this->user(['is_super_admin' => true, 'role' => 'ADMINISTRATEUR']);
        $this->as($manager)->postJson('/api/contracts', $this->form())->assertCreated();
        $this->assertCount(1, $this->notes($super));
    }

    public function test_un_destinataire_aux_anciennes_categories_reste_modifiable(): void
    {
        $super = $this->user(['is_super_admin' => true, 'role' => 'ADMINISTRATEUR']);
        $poste = DB::table('positions')->insertGetId(['project_id' => $this->prise->id, 'name' => 'COMPTABLE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('alert_recipients')->insertGetId(['project_id' => $this->prise->id, 'email' => 'ancien@example.test', 'name' => 'Ancien', 'position_id' => $poste, 'categories' => 'RETARD,GARANTIE,DECOMPTE,STOCK', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $vue = collect($this->as($super)->getJson('/api/alert-settings')->assertOk()->json('recipients'))->firstWhere('id', $id);
        $this->assertSame(['RETARD', 'GARANTIE'], $vue['categories']);
        $this->as($super)->putJson("/api/alert-settings/recipients/{$id}", ['email' => 'ancien@example.test', 'name' => 'Ancien', 'position_id' => $poste, 'categories' => $vue['categories']])->assertOk();
        $this->assertSame('RETARD,GARANTIE', DB::table('alert_recipients')->where('id', $id)->value('categories'));
    }

    public function test_seul_le_super_admin_choisit_les_comptes(): void
    {
        $super = $this->user(['is_super_admin' => true, 'role' => 'ADMINISTRATEUR']);
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'can_manage_users' => true]);
        $agent = $this->user();

        $this->as($admin)->putJson("/api/users/{$agent->id}/permissions", ['role' => 'UTILISATEUR', 'can_follow_new_contracts' => true])->assertForbidden();
        $this->assertFalse((bool) DB::table('users')->where('id', $agent->id)->value('can_follow_new_contracts'));

        $this->as($super)->putJson("/api/users/{$agent->id}/permissions", ['role' => 'UTILISATEUR', 'can_follow_new_contracts' => true])->assertOk();
        $this->assertTrue((bool) DB::table('users')->where('id', $agent->id)->value('can_follow_new_contracts'));
        $this->assertTrue($this->as($agent->fresh())->getJson('/api/auth/me')->assertOk()->json('user.permissions.canFollowNewContracts'));
    }
}
