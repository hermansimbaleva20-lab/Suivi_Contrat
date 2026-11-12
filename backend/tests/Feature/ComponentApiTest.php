<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Décision du 10/11/2026 : les composantes du projet (intitulé, budget en UC) s'enregistrent au préalable sur une page
 * réservée (coordination nationale, « Gérer les paramètres ») ; un contrat en choisit une, facultativement.
 */
class ComponentApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private int $cn;
    private int $pool;
    private int $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $unit = fn (string $type, string $code) => DB::table('organizational_units')->insertGetId(['project_id' => $this->prise->id, 'type' => $type, 'code' => $code, 'name' => $code, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->cn = $unit('COORDINATION', 'CN');
        $this->pool = $unit('POOL', 'KGA');
        $this->company = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'SZTC', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(array $attrs): User
    {
        $u = new User();
        $u->forceFill($attrs + ['project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false])->save();
        return $u;
    }

    private function as(User $u)
    {
        $this->app['auth']->forgetGuards();
        return $this->actingAs($u, 'sanctum')->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    private function contract(array $extra = []): array
    {
        return $extra + ['reference' => uniqid('C-'), 'company_id' => $this->company, 'category' => 'TRAVAUX', 'province' => 'Kasaï-Oriental', 'start_date' => '2026-01-15', 'duration_months' => 12,
            'initial_financing_rate' => '0', 'work_mode' => 'Lourd', 'guarantee_rate' => '5', 'guarantee_bank_name' => 'Rawbank', 'sites' => [['name' => 'Tshilundu', 'amount' => '100000']]];
    }

    public function test_page_reservee_a_la_coordination_nationale(): void
    {
        $national = $this->user(['can_manage_settings' => true, 'organizational_unit_id' => $this->cn]);
        $poolAdmin = $this->user(['can_manage_settings' => true, 'organizational_unit_id' => $this->pool]);
        $agent = $this->user(['can_manage_contracts' => true, 'organizational_unit_id' => $this->cn]);

        $this->as($agent)->getJson('/api/components')->assertForbidden();
        $this->as($poolAdmin)->postJson('/api/components', ['name' => 'X'])->assertForbidden();

        $id = $this->as($national)->postJson('/api/components', ['name' => 'Réalisation de 22 systèmes d’AEP', 'budget' => '12 010 331'])->assertCreated()->json('id');
        $this->as($national)->postJson('/api/components', ['name' => 'Réalisation de 22 systèmes d’AEP'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->as($national)->postJson('/api/components', ['name' => 'Autre', 'budget' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('budget');
        $this->as($national)->putJson("/api/components/{$id}", ['name' => 'Réalisation de 22 systèmes d’AEP en milieu rural', 'budget' => '12010331'])->assertOk();
        $this->assertEquals(12010331, DB::table('project_components')->where('id', $id)->value('budget'));
        $this->assertDatabaseHas('audit_logs', ['entity_name' => 'ProjectComponent', 'action' => 'MODIFICATION']);
        // Proposée au formulaire du contrat par les référentiels, lisibles de tous.
        $this->assertSame('Réalisation de 22 systèmes d’AEP en milieu rural', $this->as($agent)->getJson('/api/reference-data')->json('components.0.name'));
    }

    public function test_choix_facultatif_dans_le_contrat(): void
    {
        $national = $this->user(['can_manage_settings' => true, 'organizational_unit_id' => $this->cn]);
        $agent = $this->user(['can_manage_contracts' => true, 'organizational_unit_id' => $this->cn]);
        $id = $this->as($national)->postJson('/api/components', ['name' => 'AEP', 'budget' => '1000'])->assertCreated()->json('id');

        // Sans composante : accepté. Avec une composante enregistrée : reprise par la matrice avec son budget.
        $this->as($agent)->postJson('/api/contracts', $this->contract())->assertCreated();
        $contract = $this->as($agent)->postJson('/api/contracts', $this->contract(['component_id' => $id]))->assertCreated()->json('id');
        $view = $this->as($agent)->getJson("/api/contracts/{$contract}")->json('contract');
        $this->assertSame([$id, 'AEP', 1000.0], [$view['componentId'], $view['component'], (float) $view['componentBudget']]);
        // Une composante inconnue ou désactivée n'est pas proposée aux nouveaux contrats.
        $this->as($agent)->postJson('/api/contracts', $this->contract(['component_id' => 999999]))->assertStatus(422)->assertJsonValidationErrors('component_id');
        $this->as($national)->putJson("/api/components/{$id}/toggle")->assertOk();
        $this->as($agent)->postJson('/api/contracts', $this->contract(['component_id' => $id]))->assertStatus(422)->assertJsonValidationErrors('component_id');
        // Le contrat qui l'a la garde, même désactivée ; elle ne se supprime pas tant qu'elle est utilisée.
        $this->as($agent)->putJson("/api/contracts/{$contract}", $this->contract(['reference' => 'GARDE', 'component_id' => $id, 'sites' => [['id' => DB::table('contract_sites')->where('contract_id', $contract)->value('id'), 'name' => 'Tshilundu', 'amount' => '100000']]]))->assertOk();
        $this->assertSame($id, (int) DB::table('contracts')->where('id', $contract)->value('component_id'));
        $this->as($national)->deleteJson("/api/components/{$id}")->assertStatus(422);
        $unused = $this->as($national)->postJson('/api/components', ['name' => 'Inutilisée'])->assertCreated()->json('id');
        $this->as($national)->deleteJson("/api/components/{$unused}")->assertOk();
    }
}
