<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Contrats : liste paginée par 5 avec compteurs de statut ; contrat de SERVICE sans financement initial ni garantie. */
class ContractApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private ProjectPhase $phase;
    private int $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        $this->phase = ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $this->company = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'GENERTEL GROUPE', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function gestionnaire(): User
    {
        $u = new User();
        $u->forceFill([
            'project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false, 'can_manage_contracts' => true,
        ])->save();
        $this->actingAs($u, 'sanctum');
        return $u;
    }

    private function form(array $extra = []): array
    {
        return array_merge([
            'reference' => 'C-' . uniqid(), 'company_id' => $this->company, 'category' => 'TRAVAUX', 'province' => 'Kasaï-Central', 'start_date' => '2026-01-15',
            'duration_months' => 12, 'amount' => '100000', 'initial_financing_rate' => '20', 'work_mode' => 'Lourd',
            'guarantee_rate' => '5', 'guarantee_bank_name' => 'Rawbank', 'sites' => [['name' => 'Site 1', 'work_type' => '', 'description' => '', 'quantity' => '1']],
        ], $extra);
    }

    private function api()
    {
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    public function test_contrat_de_service_sans_financement_initial_ni_garantie(): void
    {
        $this->gestionnaire();
        $form = $this->form(['category' => 'SERVICE', 'selected_provinces' => ['Kasaï-Central'], 'initial_financing_rate' => '30', 'guarantee_bank_name' => '',
            'sites' => [['name' => 'Bureau', 'province' => 'Kasaï-Central', 'work_type' => 'Gardiennage', 'description' => '', 'quantity' => '1']]]);
        $id = $this->api()->postJson('/api/contracts', $form)->assertCreated()->json('id');

        $this->assertEquals(0, DB::table('contracts')->where('id', $id)->value('initial_financing_rate'));
        $this->assertFalse(DB::table('performance_guarantees')->where('contract_id', $id)->exists());

        // Les autres catégories gardent la garantie obligatoire.
        $this->api()->postJson('/api/contracts', $this->form(['guarantee_bank_name' => '']))->assertStatus(422)->assertJsonValidationErrors('guarantee_bank_name');
        $travaux = $this->api()->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $this->assertEquals(20, DB::table('contracts')->where('id', $travaux)->value('initial_financing_rate'));
        $this->assertTrue(DB::table('performance_guarantees')->where('contract_id', $travaux)->exists());
    }

    public function test_la_periode_de_garantie_suit_la_duree_du_contrat(): void
    {
        $this->gestionnaire();
        $id = $this->api()->postJson('/api/contracts', $this->form(['guarantee_start_date' => '2030-01-01', 'guarantee_end_date' => '2031-01-01']))->assertCreated()->json('id');
        $g = DB::table('performance_guarantees')->where('contract_id', $id)->first();
        $this->assertSame(['2026-01-15', '2027-01-15'], [substr($g->start_date, 0, 10), substr($g->end_date, 0, 10)]);

        // Correction de la durée du contrat : la garantie suit, tant qu'elle n'a été ni prolongée ni renouvelée.
        $form = $this->form(['reference' => DB::table('contracts')->where('id', $id)->value('reference'), 'duration_months' => 18]);
        $this->api()->putJson("/api/contracts/{$id}", $form)->assertOk();
        $this->assertSame('2027-07-15', substr(DB::table('performance_guarantees')->where('id', $g->id)->value('end_date'), 0, 10));

        DB::table('guarantee_extensions')->insert(['performance_guarantee_id' => $g->id, 'start_date' => '2027-07-15', 'end_date' => '2028-01-15', 'type' => 'PROLONGATION', 'reason' => 'Avenant', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('performance_guarantees')->where('id', $g->id)->update(['end_date' => '2028-01-15']);
        $this->api()->putJson("/api/contracts/{$id}", array_merge($form, ['duration_months' => 12]))->assertOk();
        $this->assertSame('2028-01-15', substr(DB::table('performance_guarantees')->where('id', $g->id)->value('end_date'), 0, 10));
    }

    public function test_liste_paginee_par_cinq_avec_compteurs_de_la_phase(): void
    {
        $this->gestionnaire();
        foreach (range(1, 7) as $i) $this->api()->postJson('/api/contracts', $this->form(['reference' => "C-$i"]))->assertCreated();

        $page1 = $this->api()->getJson('/api/contracts')->assertOk()->json();
        $this->assertCount(5, $page1['data']);
        $this->assertSame(['page' => 1, 'pages' => 2, 'total' => 7, 'size' => 5], $page1['meta']);
        $this->assertSame(7, array_sum($page1['statusCounts']));
        $this->assertCount(2, $this->api()->getJson('/api/contracts?page=2')->json('data'));
    }
}
