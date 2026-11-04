<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Marchés de biens : procédure BAD ou nationale, avance sur demande, pénalité de retard ; les fournitures de bureau ne changent pas. */
class GoodsProcedureApiTest extends TestCase
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
        $this->company = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'Fournisseur', 'created_at' => now(), 'updated_at' => now()]);
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

    private function form(array $extra = []): array
    {
        return $extra + ['reference' => '200', 'company_id' => $this->company, 'category' => 'BIENS', 'selected_provinces' => ['Kasaï-Central'], 'start_date' => '2025-01-01', 'duration_months' => 6, 'amount' => '100000',
            'initial_financing_rate' => '10', 'guarantee_rate' => '10', 'guarantee_bank_name' => 'Rawbank', 'sites' => [['name' => 'Kananga', 'province' => 'Kasaï-Central']],
            'goods_procedure' => 'BAD', 'goods_origin' => 'LOCAL'];
    }

    private function national(User $u, array $extra = []): int
    {
        return $this->as($u)->postJson('/api/contracts', $this->form($extra + ['goods_procedure' => 'NATIONALE', 'initial_financing_rate' => '20', 'guarantee_rate' => '5', 'delay_penalty_rate' => '0,05', 'delay_penalty_cap' => '10']))->assertCreated()->json('id');
    }

    private function advanceForm(array $extra = []): array
    {
        return $extra + ['request_date' => '2025-01-10', 'request_reference' => 'Lettre 12/2025', 'guarantee_bank' => 'Rawbank', 'guarantee_number' => 'GR-01', 'guarantee_amount' => '10000', 'guarantee_end_date' => '2025-07-01'];
    }

    public function test_creation_d_un_marche_selon_la_procedure(): void
    {
        $u = $this->user(['can_manage_contracts' => true]);
        $this->as($u)->getJson('/api/reference-data')->assertOk()->assertJsonPath('goodsProcedures.rules.NATIONALE.LOCAL.advanceRate', 20);

        $this->as($u)->postJson('/api/contracts', $this->form(['goods_procedure' => '']))->assertStatus(422)->assertJsonValidationErrors('goods_procedure');
        $this->as($u)->postJson('/api/contracts', $this->form(['goods_origin' => '']))->assertStatus(422)->assertJsonValidationErrors('goods_origin');
        $this->as($u)->postJson('/api/contracts', $this->form(['initial_financing_rate' => '15']))->assertStatus(422)->assertJsonValidationErrors('payment_schedule');

        $id = $this->as($u)->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $d = $this->as($u)->getJson("/api/contracts/{$id}")->assertOk()->json();
        $this->assertSame(['BAD', 'LOCAL', true, false], [$d['contract']['goodsProcedure'], $d['contract']['goodsOrigin'], $d['contract']['advanceOnRequest'], $d['contract']['canRenew']]);
        $this->assertSame(['LIVRAISON', 'ACCEPTATION'], array_column($d['contract']['paymentSchedule'], 'key'));
        $this->assertEquals(10000, $d['contract']['advanceDue']);
        $this->assertEquals(0, $d['contract']['initialFinancing']); // pas encore demandée
        $this->assertEquals(10000, $d['guarantee']['amount']); // 10 % de garantie en procédure BAD
        $this->assertNotEmpty($d['goodsRules']['documents']);
        $this->assertNull($d['contract']['delayPenaltyRate']);

        // Taux modifiables, tant que le total fait 100 %.
        $other = $this->as($u)->postJson('/api/contracts', $this->form(['reference' => '201', 'initial_financing_rate' => '20', 'payment_schedule' => [['key' => 'LIVRAISON', 'rate' => '70'], ['key' => 'ACCEPTATION', 'rate' => '10']]]))->assertCreated()->json('id');
        $this->assertEquals(70, $this->as($u)->getJson("/api/contracts/{$other}")->json('contract.paymentSchedule.0.rate'));

        // Le renouvellement est réservé aux fournitures de bureau.
        $this->as($u)->postJson("/api/contracts/{$id}/renew", ['reference' => '200/R1', 'date' => '2025-07-01', 'period_start' => '2025-07-01', 'months' => 12, 'added_amount' => '100', 'reason' => 'Nouvelle période'])->assertStatus(422);
    }

    public function test_les_fournitures_de_bureau_gardent_les_regles_habituelles(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $id = $this->as($u)->postJson('/api/contracts', $this->form(['goods_procedure' => 'FOURNITURES_BUREAU', 'goods_origin' => 'LOCAL', 'initial_financing_rate' => '20', 'guarantee_rate' => '5']))->assertCreated()->json('id');
        $d = $this->as($u)->getJson("/api/contracts/{$id}")->json();
        $this->assertSame(['FOURNITURES_BUREAU', null, false, true, null, []], [$d['contract']['goodsProcedure'], $d['contract']['goodsOrigin'], $d['contract']['advanceOnRequest'], $d['contract']['canRenew'], $d['goodsRules'], $d['contract']['paymentSchedule']]);
        $this->assertEquals(20000, $d['contract']['initialFinancing']); // comptée dès la signature
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertStatus(422);

        // Décompte au calcul habituel, sans demande d'avance et sans pénalité.
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2026-01-10', 'gross_amount' => '100000'])->assertOk();
        $row = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('rows.0');
        $this->assertSame([20000.0, 5000.0, 0.0, 75000.0], [(float) $row['advance'], (float) $row['guarantee'], (float) $row['penalty'], (float) $row['net']]);

        $this->as($u)->postJson("/api/contracts/{$id}/renew", ['reference' => '200/R1', 'date' => '2026-01-10', 'period_start' => Dates::today(), 'months' => 12, 'added_amount' => '100', 'reason' => 'Nouvelle période'])->assertOk();
    }

    public function test_avance_sur_demande_contre_garantie_de_restitution(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $id = $this->as($u)->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $reader = $this->user([]);

        $this->as($reader)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertForbidden();
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm(['guarantee_amount' => '9999,99']))->assertStatus(422)->assertJsonValidationErrors('guarantee_amount');
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm(['guarantee_number' => '']))->assertStatus(422)->assertJsonValidationErrors('guarantee_number');
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm(['guarantee_end_date' => '2025-01-01']))->assertStatus(422)->assertJsonValidationErrors('guarantee_end_date');

        $advance = $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertCreated()->json('id');
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertStatus(422); // une seule demande
        $this->assertDatabaseHas('audit_logs', ['entity_name' => 'ContractAdvance', 'entity_id' => (string) $advance]);

        // Demandée, pas encore payée : rien ne compte, et aucun décompte tant qu'elle n'est pas traitée.
        $c = $this->as($u)->getJson("/api/contracts/{$id}")->json('contract');
        $this->assertSame(['DEMANDEE', 0.0, 100000.0], [$c['advanceRequest']['status'], (float) $c['initialFinancing'], (float) $c['remainingAmount']]);
        $this->assertFalse($this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('canSubmit'));
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-02-01', 'gross_amount' => '80000'])->assertStatus(422);

        $this->as($u)->postJson("/api/advances/{$advance}/pay", ['paid_date' => '2025-01-05', 'payment_reference' => 'VIR-1'])->assertStatus(422)->assertJsonValidationErrors('paid_date');
        $this->as($u)->postJson("/api/advances/{$advance}/pay", ['paid_date' => '2025-01-20', 'payment_reference' => 'VIR-1'])->assertOk();
        $this->as($u)->postJson("/api/advances/{$advance}/pay", ['paid_date' => '2025-01-20', 'payment_reference' => 'VIR-1'])->assertStatus(422);
        $c = $this->as($u)->getJson("/api/contracts/{$id}")->json('contract');
        $this->assertSame([10000.0, 10.0, 90000.0], [(float) $c['initialFinancing'], (float) $c['paymentRate'], (float) $c['remainingAmount']]);

        // Le décompte retient alors l'avance et la garantie aux taux de la procédure : 80 000 − 8 000 − 8 000.
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-02-01', 'gross_amount' => '80000'])->assertOk();
        $row = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('rows.0');
        $this->assertSame([8000.0, 8000.0, 0.0, 64000.0, 2000.0], [(float) $row['advance'], (float) $row['guarantee'], (float) $row['penalty'], (float) $row['net'], (float) $row['advanceBalance']]);

        // L'avance réglée alimente les lignes : son paiement ne s'annule plus, et les règles du marché sont figées.
        $this->as($u)->postJson("/api/advances/{$advance}/cancel", ['reason' => 'Erreur de saisie'])->assertStatus(422);
        $this->as($u)->putJson("/api/contracts/{$id}", $this->form(['goods_origin' => 'ETRANGER']))->assertStatus(422)->assertJsonValidationErrors('goods_procedure');
    }

    public function test_avance_rejetee_redemandee_ou_perdue_apres_un_decompte(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $id = $this->as($u)->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $first = $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertCreated()->json('id');
        $this->as($u)->postJson("/api/advances/{$first}/cancel", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($u)->postJson("/api/advances/{$first}/cancel", ['reason' => 'Garantie non conforme'])->assertOk();
        $this->assertNull($this->as($u)->getJson("/api/contracts/{$id}")->json('contract.advanceRequest'));
        $second = $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertCreated()->json('id');
        $this->as($u)->postJson("/api/advances/{$second}/cancel", ['reason' => 'Demande retirée'])->assertOk();

        // Sans avance, le décompte ne retient rien au titre de l'avance ; elle ne peut plus être demandée ensuite.
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-02-01', 'gross_amount' => '1000'])->assertOk();
        $row = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('rows.0');
        $this->assertSame([0.0, 100.0, 900.0], [(float) $row['advance'], (float) $row['guarantee'], (float) $row['net']]);
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertStatus(422);
    }

    public function test_penalite_nationale_deduite_du_dernier_decompte(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $chef = $this->user(['can_validate_decomptes' => true]);
        $id = $this->national($u); // fin du contrat : 01/07/2025
        $this->assertEquals(0.05, $this->as($u)->getJson("/api/contracts/{$id}")->json('contract.delayPenaltyRate'));

        // Décompte intermédiaire, même en retard : aucune pénalité.
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-09-01', 'gross_amount' => '40000'])->assertOk();
        $d = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json();
        $this->assertSame([0.0, 30000.0 + 8000.0 - 0.0], [(float) $d['rows'][0]['penalty'], (float) $d['rows'][0]['net'] + (float) $d['rows'][0]['advance']]);

        // Il atteint le montant du marché : dernier décompte, date de réception obligatoire.
        $last = ['number' => 'DECOMPTE N°2', 'operation_date' => '2025-09-10', 'gross_amount' => '60000'];
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", $last)->assertStatus(422)->assertJsonValidationErrors('reception_date');
        // 01/07 → 12/08/2025 : 42 jours, 6 semaines × 0,05 % × 100 000 = 300.
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", $last + ['reception_date' => '2025-08-12'])->assertOk()->assertJsonFragment(['message' => 'DECOMPTE N°2 enregistré et transmis au chef de cellule infrastructures pour validation. Pénalité de retard déduite : 300,00 USD (6 semaine(s) de retard).']);
        $d = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json();
        $row = $d['rows'][1];
        $this->assertSame([300.0, 6, true, '2025-08-12'], [(float) $row['penalty'], $row['penaltyWeeks'], $row['isFinal'], $row['receptionDate']]);
        // Sans avance versée : 60 000 − garantie 5 % (3 000) − 300.
        $this->assertSame([3000.0, 56700.0], [(float) $row['guarantee'], (float) $row['net']]);
        $this->assertFalse($d['canSubmit']);
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°3', 'operation_date' => '2025-09-11', 'gross_amount' => '10'])->assertStatus(422);

        // Pénalité validée : hors du reste à payer, et le paiement proposé est net de pénalité.
        $this->assertEquals(0, $this->as($u)->getJson("/api/contracts/{$id}")->json('contract.penalties'));
        foreach ($d['rows'] as $line) $this->as($chef)->postJson("/api/decomptes/{$line['id']}/decision", ['decision' => 'VALIDE'])->assertOk();
        $this->assertEquals(300, $this->as($u)->getJson("/api/contracts/{$id}")->json('contract.penalties'));
        $this->assertEquals(100000 - 300, $this->as($u)->getJson("/api/contracts/{$id}")->json('contract.remainingAmount'));
        $toPay = collect($this->as($u)->getJson("/api/payments?contract_id={$id}")->assertOk()->json('toPay'))->firstWhere('number', 'DECOMPTE N°2');
        $this->assertEquals(56700, $toPay['amount']);
    }

    public function test_reception_a_temps_ou_decompte_marque_comme_dernier(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $id = $this->national($u);
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-09-01', 'gross_amount' => '1000', 'is_final' => true])->assertStatus(422)->assertJsonValidationErrors('reception_date');
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-09-01', 'gross_amount' => '1000', 'is_final' => true, 'reception_date' => '2025-06-20'])->assertOk();
        $row = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('rows.0');
        $this->assertSame([0.0, 0, true], [(float) $row['penalty'], $row['penaltyWeeks'], $row['isFinal']]);

        // La procédure BAD n'a pas de pénalité : la case et la date n'ont aucun effet.
        $bad = $this->as($u)->postJson('/api/contracts', $this->form(['reference' => '201']))->assertCreated()->json('id');
        $this->as($u)->postJson("/api/contracts/{$bad}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-09-01', 'gross_amount' => '100000', 'is_final' => true, 'reception_date' => '2025-08-12'])->assertOk();
        $row = $this->as($u)->getJson("/api/decomptes?contract_id={$bad}")->json('rows.0');
        $this->assertSame([0.0, false], [(float) $row['penalty'], $row['isFinal']]);
    }

    public function test_les_regles_restent_modifiables_tant_que_le_marche_n_a_pas_commence(): void
    {
        $u = $this->user(['can_manage_contracts' => true]);
        $id = $this->as($u)->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $site = DB::table('contract_sites')->where('contract_id', $id)->value('id');
        $sites = [['id' => $site, 'name' => 'Kananga', 'province' => 'Kasaï-Central']];
        $this->as($u)->putJson("/api/contracts/{$id}", $this->form(['sites' => $sites, 'goods_procedure' => 'NATIONALE', 'initial_financing_rate' => '20', 'guarantee_rate' => '5', 'delay_penalty_rate' => '0.05', 'delay_penalty_cap' => '10', 'payment_schedule' => [['key' => 'RECEPTION', 'rate' => '80']]]))->assertOk();
        $d = $this->as($u)->getJson("/api/contracts/{$id}")->json();
        $this->assertSame('NATIONALE', $d['contract']['goodsProcedure']);
        $this->assertEquals(5, $d['guarantee']['rate']);
        $this->assertEquals(5000, $d['guarantee']['amount']);
    }
}
