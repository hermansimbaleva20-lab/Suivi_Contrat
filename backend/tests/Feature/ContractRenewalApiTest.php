<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Dates;
use App\Support\GuaranteeAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Renouvellement d'un contrat de biens : même contrat, numéro à suffixe, sans nouvelle avance ni garantie exigée. */
class ContractRenewalApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private ProjectPhase $phase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        $this->phase = ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
    }

    private function user(array $attrs): User
    {
        $u = new User();
        $u->forceFill(array_merge(['project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false], $attrs))->save();
        return $u;
    }

    private function as(User $u): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    /** Contrat de 1 000 USD, avance 20 %, garantie 5 %, du 01/01/2026 au 01/01/2027. */
    private function contract(string $category = 'BIENS', string $ref = '144'): int
    {
        $co = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => "Entreprise {$ref}", 'email' => 'contact@fournisseur.test', 'created_at' => now(), 'updated_at' => now()]);
        $c = DB::table('contracts')->insertGetId(['project_id' => $this->prise->id, 'phase_id' => $this->phase->id, 'company_id' => $co, 'reference' => $ref, 'category' => $category, 'province' => 'Kasaï-Central', 'amount' => 1000,
            'initial_financing_rate' => 20, 'start_date' => '2026-01-01', 'end_date' => '2027-01-01', 'duration_months' => 12, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('contract_sites')->insert(['contract_id' => $c, 'province' => 'Kasaï-Central', 'name' => 'Bureau', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('performance_guarantees')->insert(['project_id' => $this->prise->id, 'contract_id' => $c, 'company_id' => $co, 'bank_name' => 'Rawbank', 'account_number' => 'GBE-1', 'rate' => 5, 'amount' => 50,
            'start_date' => '2026-01-01', 'end_date' => '2027-01-01', 'created_at' => now(), 'updated_at' => now()]);
        return $c;
    }

    private function form(array $extra = []): array
    {
        return $extra + ['reference' => '144/R1', 'date' => '2027-01-01', 'period_start' => '2027-01-01', 'months' => 12, 'added_amount' => '500', 'reason' => 'Fournitures de bureau de 2027'];
    }

    public function test_renouvellement_d_un_contrat_de_biens(): void
    {
        $u = $this->user(['can_manage_contracts' => true]);
        $id = $this->contract();

        $this->as($this->user([]))->postJson("/api/contracts/{$id}/renew", $this->form())->assertForbidden();
        $this->as($u)->postJson('/api/contracts/' . $this->contract('TRAVAUX', '200') . '/renew', $this->form())->assertStatus(422);
        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form(['reference' => '144']))->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form(['reference' => '200']))->assertStatus(422)->assertJsonValidationErrors('reference');
        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form(['period_start' => '2026-01-01', 'months' => 6]))->assertStatus(422)->assertJsonValidationErrors('months');
        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form(['reason' => 'x', 'added_amount' => 'abc']))->assertStatus(422)->assertJsonValidationErrors(['reason', 'added_amount']);

        // Avant : le numéro proposé ; après : même contrat, nouveau numéro, nouvelle fin, montant augmenté.
        $this->assertSame('144/R1', $this->as($u)->getJson("/api/contracts/{$id}")->assertOk()->json('contract.nextRenewalNumber'));
        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form())->assertOk();
        $c = DB::table('contracts')->where('id', $id)->first();
        $this->assertSame(['144/R1', 1500.0, 500.0, 1, '2028-01-01'], [$c->reference, (float) $c->amount, (float) $c->renewed_amount, (int) $c->renewal_no, substr($c->end_date, 0, 10)]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'RENOUVELLEMENT_CONTRAT', 'entity_id' => (string) $id]);

        $d = $this->as($u)->getJson("/api/contracts/{$id}")->assertOk()->json();
        $this->assertSame('144/R2', $d['contract']['nextRenewalNumber']);
        $this->assertSame(['144', '144/R1', 500, '2027-01-01', '2028-01-01'], [$d['renewals'][0]['previous_reference'], $d['renewals'][0]['new_reference'], (int) $d['renewals'][0]['added_amount'], $d['renewals'][0]['period_start'], $d['renewals'][0]['new_end']]);

        // Pas de nouvelle avance : elle reste de 200 (20 % de 1 000), le taux de paiement porte sur 1 500.
        $this->assertEquals(200, $d['contract']['initialFinancing']);
        $this->assertEqualsWithDelta(13.33, $d['contract']['paymentRate'], 0.01);
        $this->assertEquals(1300, $d['contract']['remainingAmount']);

        // Deuxième renouvellement : le suffixe suit, les montants ajoutés se cumulent, l'avance ne bouge toujours pas.
        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form(['reference' => '144/R2', 'period_start' => '2028-01-01', 'added_amount' => '300,50']))->assertOk();
        $d = $this->as($u)->getJson("/api/contracts/{$id}")->json();
        $this->assertEquals(1800.5, $d['contract']['amount']);
        $this->assertEquals(200, $d['contract']['initialFinancing']);
        $this->assertCount(2, $d['renewals']);
    }

    public function test_la_garantie_n_est_plus_exigee_ni_relancee(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_view_alerts_guarantees' => true, 'can_manage_guarantees' => true]);
        $today = Dates::today();
        $id = $this->contract();
        DB::table('contracts')->where('id', $id)->update(['start_date' => Dates::addDays($today, -400), 'end_date' => Dates::addDays($today, -30)]);
        DB::table('performance_guarantees')->where('contract_id', $id)->update(['start_date' => Dates::addDays($today, -400), 'end_date' => Dates::addDays($today, -30)]);

        $this->assertSame('RETARD', collect(GuaranteeAlerts::plan($this->prise->id))->firstWhere('contractId', $id)['kind']);
        $this->assertSame(1, $this->as($u)->getJson('/api/auth/me')->json('alerts.guarantees'));

        $start = Dates::addDays($today, -30);
        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form(['period_start' => $start]))->assertOk();

        $row = collect(GuaranteeAlerts::plan($this->prise->id))->firstWhere('contractId', $id);
        $this->assertSame(['RENOUVELE', null, false, false], [$row['state'], $row['kind'], $row['due'], $row['satisfactionDue']]);
        $this->assertSame(0, $this->as($u)->getJson('/api/auth/me')->json('alerts.guarantees'));
        $this->assertSame([], $this->as($u)->getJson('/api/alerts')->assertOk()->json('guarantees'));
        $g = $this->as($u)->getJson('/api/guarantees')->assertOk()->json('data.0');
        $this->assertSame([true, false, 'Non exigée'], [$g['contractRenewed'], $g['needsAttention'], $g['statusLabel']]);

        // La garantie garde sa période et son montant, même après une correction du contrat.
        $form = ['reference' => '144/R1', 'company_id' => DB::table('contracts')->where('id', $id)->value('company_id'), 'category' => 'BIENS', 'province' => 'Kasaï-Central', 'selected_provinces' => ['Kasaï-Central'],
            'start_date' => substr(DB::table('contracts')->where('id', $id)->value('start_date'), 0, 10), 'duration_months' => DB::table('contracts')->where('id', $id)->value('duration_months'), 'amount' => '1500',
            'initial_financing_rate' => '20', 'sites' => [['id' => DB::table('contract_sites')->where('contract_id', $id)->value('id'), 'name' => 'Bureau', 'province' => 'Kasaï-Central', 'work_type' => '', 'description' => '', 'quantity' => '1']]];
        $end = substr(DB::table('contracts')->where('id', $id)->value('end_date'), 0, 10);
        $this->as($u)->putJson("/api/contracts/{$id}", $form)->assertOk();
        $this->assertSame($end, substr(DB::table('contracts')->where('id', $id)->value('end_date'), 0, 10));
        $guarantee = DB::table('performance_guarantees')->where('contract_id', $id)->first();
        $this->assertSame(Dates::addDays($today, -30), substr($guarantee->end_date, 0, 10));
        $this->assertEquals(50, $guarantee->amount);

        // Le montant ne peut pas descendre sous les montants ajoutés.
        $this->as($u)->putJson("/api/contracts/{$id}", ['amount' => '400'] + $form)->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_les_decomptes_continuent_avec_la_retenue_sans_nouvelle_avance(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $id = $this->contract();
        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form())->assertOk();

        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2026-02-01', 'gross_amount' => '100'])->assertOk();
        $d = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->assertOk()->json();
        $this->assertEquals(200, $d['selected']['advanceReceived']);
        $this->assertSame([20.0, 5.0, 75.0], [(float) $d['rows'][0]['advance'], (float) $d['rows'][0]['guarantee'], (float) $d['rows'][0]['net']]); // retenue B.E. maintenue
        $this->assertEquals(1225, $d['rows'][0]['contractBalance']); // 1 500 − 200 − 75
    }

    public function test_une_retenue_reversee_avant_le_renouvellement_ne_bloque_pas_la_nouvelle_periode(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $chef = $this->user(['can_validate_decomptes' => true]);
        $id = $this->contract();
        $first = $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2026-02-01', 'gross_amount' => '100'])->assertOk()->json('id');
        $this->as($chef)->postJson("/api/decomptes/{$first}/decision", ['decision' => 'VALIDE'])->assertOk();
        DB::table('contracts')->where('id', $id)->update(['completed' => true]);
        $rel = $this->as($u)->postJson("/api/contracts/{$id}/decomptes/release", ['operation_date' => '2026-03-01'])->assertOk()->json('id');

        // Reversement en attente : à traiter d'abord.
        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form())->assertStatus(422);
        $this->as($chef)->postJson("/api/decomptes/{$rel}/decision", ['decision' => 'VALIDE'])->assertOk();
        $this->assertFalse($this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('canSubmit'));
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°2', 'operation_date' => '2026-04-01', 'gross_amount' => '100'])->assertStatus(422);

        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form())->assertOk();
        $this->assertFalse((bool) DB::table('contracts')->where('id', $id)->value('completed'));
        $this->assertTrue($this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('canSubmit'));
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°2', 'operation_date' => '2026-04-01', 'gross_amount' => '100'])->assertOk();
    }

    public function test_un_contrat_resilie_ne_se_renouvelle_pas(): void
    {
        $u = $this->user(['can_manage_contracts' => true]);
        $id = $this->contract();
        DB::table('contracts')->where('id', $id)->update(['termination_date' => '2026-06-01', 'termination_reason' => 'Motif de test']);
        $this->as($u)->postJson("/api/contracts/{$id}/renew", $this->form())->assertStatus(422);
    }
}
