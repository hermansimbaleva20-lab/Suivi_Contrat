<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Tableau de bord : répartition des contrats par province, sur le modèle du tableau du rapport du projet. */
class ProvinceContractsApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private ProjectPhase $phase;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        $this->phase = ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $this->user = new User();
        $this->user->forceFill(['project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => 'agent@example.test', 'password' => Hash::make('secret123'), 'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false])->save();
    }

    private function contract(string $number, string $company, array $sites, array $attrs = []): int
    {
        $companyId = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => $company, 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('contracts')->insertGetId($attrs + ['project_id' => $this->prise->id, 'phase_id' => $this->phase->id, 'company_id' => $companyId, 'reference' => $number, 'category' => 'TRAVAUX', 'province' => $sites[0][0] ?? null,
            'amount' => 1000, 'start_date' => '2024-01-08', 'end_date' => '2025-01-08', 'duration_months' => 12, 'initial_financing_rate' => 0, 'work_type_text' => 'Système d’AEP', 'completed' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($sites as [$province, $workType]) {
            DB::table('contract_sites')->insert(['contract_id' => $id, 'province' => $province, 'work_type' => $workType, 'name' => "Site {$province}", 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $id;
    }

    private function table(): array
    {
        $this->actingAs($this->user, 'sanctum');
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json'])->getJson('/api/dashboard')->assertOk()->json('provinceContracts');
    }

    public function test_contrats_regroupes_par_province_et_tries_par_numero(): void
    {
        $this->contract('021', 'ENTRASCO', [['Kwilu', 'Système d’AEP']]);
        $this->contract('004', 'GCK', [['Kwilu', 'Forage']]);
        $this->contract('100', 'POWER', [['Mai-Ndombe', 'Système d’AEP']]);
        $this->contract('017', 'RAM CONSTRUCT', [['Kasaï-Central', 'Système d’AEP']]);
        $this->contract('18', 'ASPHAR', [['Kasaï Central', 'Système d’AEP']]);

        $t = $this->table();
        $this->assertSame(['PROVINCE DU KASAÏ-CENTRAL', 'PROVINCE DU KWILU', 'PROVINCE DE MAI-NDOMBE'], array_column($t, 'title'));
        $this->assertSame(['017', '18'], array_column($t[0]['contracts'], 'number'), 'Écritures d’une même province réunies, tri numérique.');
        $this->assertSame(['004', '021'], array_column($t[1]['contracts'], 'number'));
        $this->assertSame(['number' => '004', 'company' => 'GCK', 'category' => 'TRAVAUX', 'workType' => 'Forage', 'startDate' => '2024-01-08', 'months' => 12],
            array_intersect_key($t[1]['contracts'][0], array_flip(['number', 'company', 'category', 'workType', 'startDate', 'months'])));
    }

    public function test_contrat_sur_plusieurs_provinces_et_sans_province(): void
    {
        $this->contract('030', 'SCC', [['Kwango', 'Forage'], ['Kwilu', 'Système d’AEP'], ['Kwilu', 'Forage']]);
        $this->contract('031', 'RTM', [], ['province' => null, 'category' => 'SERVICE', 'work_type_text' => null]);

        $t = $this->table();
        $this->assertSame(['PROVINCE DU KWANGO', 'PROVINCE DU KWILU', 'PROVINCE NON RENSEIGNÉE'], array_column($t, 'title'));
        $this->assertSame('Forage', $t[0]['contracts'][0]['workType']);
        $this->assertSame('Système d’AEP; Forage', $t[1]['contracts'][0]['workType']);
        $this->assertSame(['031', 'SERVICE'], [$t[2]['contracts'][0]['number'], $t[2]['contracts'][0]['category']]);
    }

    public function test_periode_renouvelee_et_fournitures_de_bureau_ecartees(): void
    {
        $id = $this->contract('040', 'AGIES', [['Kasaï', 'Fournitures']], ['category' => 'BIENS', 'renewal_no' => 1]);
        DB::table('contract_renewals')->insert(['contract_id' => $id, 'renewal_no' => 1, 'date' => '2026-01-20', 'previous_reference' => '040', 'new_reference' => '040', 'previous_amount' => 1000, 'period_start' => '2026-02-01', 'new_end_date' => '2026-08-01', 'months' => 6, 'previous_end_date' => '2025-01-08', 'added_amount' => 0, 'reason' => 'Renouvellement', 'created_at' => now(), 'updated_at' => now()]);
        $this->contract('041', 'BUREAU ETRE', [['Kasaï', 'Fourniture de bureau']], ['category' => 'BIENS', 'goods_procedure' => 'FOURNITURES_BUREAU', 'amount' => 0]);

        $t = $this->table();
        $this->assertCount(1, $t);
        $this->assertSame(['040'], array_column($t[0]['contracts'], 'number'));
        $this->assertSame(['2026-02-01', 6], [$t[0]['contracts'][0]['startDate'], $t[0]['contracts'][0]['months']]);
    }
}
