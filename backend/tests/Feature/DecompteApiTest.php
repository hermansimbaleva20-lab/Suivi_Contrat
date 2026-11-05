<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\AlertEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Décomptes : saisie, calcul des retenues, validation ou rejet par le chef de cellule infrastructures, contrat de service sans avance. */
class DecompteApiTest extends TestCase
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

    private function user(array $attrs = []): User
    {
        $u = new User();
        $u->forceFill(array_merge([
            'project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false,
        ], $attrs))->save();
        return $u;
    }

    private function as(User $u): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    private function contract(string $category = 'TRAVAUX', float $amount = 10933203.25, float $rate = 20): int
    {
        $company = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'GENERTEL Groupe ' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('contracts')->insertGetId([
            'project_id' => $this->prise->id, 'phase_id' => $this->phase->id, 'company_id' => $company, 'reference' => 'N°144-' . uniqid(), 'category' => $category,
            'amount' => $amount, 'initial_financing_rate' => $rate, 'start_date' => '2026-01-01', 'duration_months' => 12, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('performance_guarantees')->insert(['project_id' => $this->prise->id, 'contract_id' => $id, 'company_id' => $company, 'bank_name' => 'Rawbank', 'rate' => 5, 'amount' => $amount * 0.05, 'start_date' => '2026-01-01', 'end_date' => '2027-01-01', 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function test_saisie_puis_validation_par_le_comptable(): void
    {
        $c = $this->contract();
        $saisie = $this->user(['can_manage_payments' => true]);
        $comptable = $this->user(['can_validate_decomptes' => true]);

        $this->as($this->user())->getJson('/api/decomptes')->assertForbidden();
        $this->as($comptable)->postJson("/api/contracts/{$c}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2026-02-25', 'gross_amount' => '1'])->assertForbidden();

        $id = $this->as($saisie)->postJson("/api/contracts/{$c}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2026-02-25', 'gross_amount' => '870 459,95'])->assertOk()->json('id');
        $this->as($saisie)->postJson("/api/contracts/{$c}/decomptes", ['number' => 'decompte n°1', 'operation_date' => '2026-02-25', 'gross_amount' => '10'])->assertStatus(422)->assertJsonValidationErrors('number');
        // Le décompte transmis arrive dans l'espace du comptable.
        $inbox = $this->as($comptable)->getJson('/api/notifications')->assertOk()->json();
        $this->assertSame(1, $inbox['unread']);
        $this->assertSame("/decomptes?contrat={$c}", $inbox['notifications'][0]['link']);
        $this->assertSame(0, $this->as($saisie)->getJson('/api/notifications')->json('unread'));

        $d = $this->as($comptable)->getJson("/api/decomptes?contract_id={$c}")->assertOk()->json();
        $this->assertEquals(2186640.65, $d['selected']['advanceReceived']);
        $this->assertEquals(0.05, $d['selected']['guaranteeRate']);
        $row = $d['rows'][0];
        $this->assertEquals(174091.99, $row['advance']);
        $this->assertEquals(43523.0, $row['guarantee']);
        $this->assertEquals(652844.96, $row['net']);
        $this->assertSame('EN_ATTENTE', $row['status']);
        $this->assertTrue($row['canDecide']);
        $this->assertSame(0, $d['totals']['validated']);
        $this->assertSame('DECOMPTE N°2', $d['nextNumber']);

        // Celui qui saisit ne valide pas ; le rejet exige un motif.
        $self = $this->user(['can_manage_payments' => true, 'can_validate_decomptes' => true]);
        $own = $this->as($self)->postJson("/api/contracts/{$c}/decomptes", ['number' => 'DECOMPTE N°2', 'operation_date' => '2026-03-01', 'gross_amount' => '100'])->json('id');
        $this->as($self)->postJson("/api/decomptes/{$own}/decision", ['decision' => 'VALIDE'])->assertStatus(422);
        // Les lignes se décident dans l'ordre : la N°2 attend la N°1.
        $this->as($comptable)->postJson("/api/decomptes/{$own}/decision", ['decision' => 'REJETE', 'comment' => 'Montant non justifié'])->assertStatus(422);

        $this->as($comptable)->postJson("/api/decomptes/{$id}/decision", ['decision' => 'VALIDE'])->assertOk();
        $this->as($comptable)->postJson("/api/decomptes/{$id}/decision", ['decision' => 'REJETE', 'comment' => 'Trop tard'])->assertStatus(422);
        // Une fois décidé, le décompte quitte la liste « à traiter » ; l'auteur de la saisie est informé de la décision.
        $this->assertDatabaseHas('user_notifications', ['user_id' => $comptable->id, 'entity_id' => (string) $id, 'kind' => 'DECOMPTE_A_VALIDER']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $comptable->id, 'entity_id' => (string) $id, 'read_at' => null]);
        $kinds = array_column($this->as($saisie)->getJson('/api/notifications')->json('notifications'), 'kind');
        $this->assertContains('DECOMPTE_DECISION', $kinds);
        $this->assertContains('DECOMPTE_A_PAYER', $kinds);

        // La validation ne crée pas le paiement : le gestionnaire voit le message avec le montant de la colonne MONTANT PAYÉ.
        $this->assertNull(DB::table('contract_payments')->where('decompte_id', $id)->first());
        $toPay = $this->as($saisie)->getJson("/api/payments?contract_id={$c}")->assertOk()->json('toPay');
        $this->assertCount(1, $toPay);
        $this->assertEquals(652844.96, $toPay[0]['amount']);
        $this->assertSame([$id, 'DECOMPTE N°1', $c], [$toPay[0]['id'], $toPay[0]['number'], $toPay[0]['contractId']]);
        $this->assertSame([], $this->as($this->user(['can_view_situation' => true]))->getJson("/api/payments?contract_id={$c}")->assertOk()->json('toPay'));

        // Il renseigne les champs ; le montant reste celui du décompte, quoi qu'envoie le navigateur.
        $this->as($saisie)->postJson('/api/payments', ['contract_id' => $c, 'decompte_id' => $id, 'reference' => 'OV-77', 'observation' => 'Virement'])->assertStatus(422); // date obligatoire
        $res = $this->as($saisie)->postJson('/api/payments', ['contract_id' => $c, 'decompte_id' => $id, 'date' => '2026-03-02', 'amount' => '1', 'reference' => 'OV-77', 'observation' => 'Virement'])->assertCreated();
        $payment = DB::table('contract_payments')->where('id', $res->json('id'))->first();
        $this->assertEquals(652844.96, $payment->amount);
        $this->assertSame(['OV-77', 'Virement', $id], [$payment->reference, $payment->observation, (int) $payment->decompte_id]);
        $this->as($saisie)->postJson('/api/payments', ['contract_id' => $c, 'decompte_id' => $id, 'date' => '2026-03-02'])->assertStatus(422); // un seul paiement par décompte
        $this->assertSame([], $this->as($saisie)->getJson("/api/payments?contract_id={$c}")->json('toPay'));
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $saisie->id, 'entity_id' => (string) $id, 'kind' => 'DECOMPTE_A_PAYER', 'read_at' => null]);

        // Correction : la date et les références changent, pas le montant. Suppression : le décompte revient dans la liste.
        $this->as($saisie)->putJson("/api/payments/{$payment->id}", ['date' => '2026-03-03', 'amount' => '1', 'reference' => 'OV-78', 'observation' => ''])->assertOk();
        $this->assertEquals(652844.96, DB::table('contract_payments')->where('id', $payment->id)->value('amount'));
        $this->assertSame('OV-78', DB::table('contract_payments')->where('id', $payment->id)->value('reference'));
        $this->as($saisie)->deleteJson("/api/payments/{$payment->id}", ['reason' => 'Erreur de saisie'])->assertOk();
        $this->assertCount(1, $this->as($saisie)->getJson("/api/payments?contract_id={$c}")->json('toPay'));

        // Un rejet exige le motif et la date de l'opération, qui ne précède pas le décompte et n'est pas dans le futur.
        $this->as($comptable)->postJson("/api/decomptes/{$own}/decision", ['decision' => 'REJETE'])->assertStatus(422)->assertJsonValidationErrors(['comment', 'decision_date']);
        $this->as($comptable)->postJson("/api/decomptes/{$own}/decision", ['decision' => 'REJETE', 'comment' => 'Montant non justifié', 'decision_date' => '2026-02-28'])
            ->assertStatus(422)->assertJsonValidationErrors('decision_date');
        $this->as($comptable)->postJson("/api/decomptes/{$own}/decision", ['decision' => 'REJETE', 'comment' => 'Montant non justifié', 'decision_date' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('decision_date');
        $this->as($comptable)->postJson("/api/decomptes/{$own}/decision", ['decision' => 'REJETE', 'comment' => 'Montant non justifié', 'decision_date' => '2026-03-05'])->assertOk();
        $this->assertSame('2026-03-05', (string) DB::table('contract_decomptes')->where('id', $own)->value('decision_date'));

        $this->assertNull(DB::table('contract_payments')->where('decompte_id', $own)->first());

        $d = $this->as($saisie)->getJson("/api/decomptes?contract_id={$c}")->json();
        $this->assertSame(1, $d['totals']['validated']);
        $this->assertSame(1, $d['totals']['rejected']);
        $this->assertEquals(652844.96, $d['totals']['net']);
        $this->assertNull($d['rows'][1]['net']);
        $this->assertDatabaseHas('audit_logs', ['entity_name' => 'Decompte', 'action' => 'VALIDATION', 'entity_id' => (string) $id]);
    }

    public function test_retenue_reversee_en_fin_de_contrat(): void
    {
        $c = $this->contract('TRAVAUX', 1000, 20);
        $saisie = $this->user(['can_manage_payments' => true]);
        $comptable = $this->user(['can_validate_decomptes' => true]);
        $id = $this->as($saisie)->postJson("/api/contracts/{$c}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2026-02-25', 'gross_amount' => '1000'])->json('id');
        $this->as($comptable)->postJson("/api/decomptes/{$id}/decision", ['decision' => 'VALIDE'])->assertOk(); // retenue 50, avance 200, payé 750

        // Contrat non terminé : la retenue ne se reverse pas encore.
        $this->as($saisie)->postJson("/api/contracts/{$c}/decomptes/release", ['operation_date' => '2026-03-01'])->assertStatus(422);
        $d = $this->as($saisie)->getJson("/api/decomptes?contract_id={$c}")->json();
        $this->assertFalse($d['release']['canRelease']);
        $this->assertEquals(50, $d['release']['amount']);

        DB::table('contracts')->where('id', $c)->update(['completed' => true]);
        $rel = $this->as($saisie)->postJson("/api/contracts/{$c}/decomptes/release", ['operation_date' => '2026-03-01'])->assertOk()->json('id');
        $this->as($saisie)->postJson("/api/contracts/{$c}/decomptes/release", ['operation_date' => '2026-03-01'])->assertStatus(422);
        $this->as($comptable)->postJson("/api/decomptes/{$rel}/decision", ['decision' => 'VALIDE'])->assertOk();

        $d = $this->as($saisie)->getJson("/api/decomptes?contract_id={$c}")->json();
        $row = collect($d['rows'])->firstWhere('id', $rel);
        $this->assertSame('LIBERATION', $row['kind']);
        $this->assertEquals(50, $row['net']);
        $this->assertEquals(0, $row['contractBalance']); // 1000 − 200 − 750 − 50 : contrat soldé
        $this->assertEquals(0, $d['totals']['held']);
        $this->assertNull(DB::table('contract_payments')->where('decompte_id', $rel)->first());
        $this->as($saisie)->postJson('/api/payments', ['contract_id' => $c, 'decompte_id' => $rel, 'date' => '2026-03-05'])->assertCreated();
        $this->assertEquals(50, DB::table('contract_payments')->where('decompte_id', $rel)->value('amount'));
        $this->assertFalse($d['canSubmit']);
    }

    public function test_contrat_de_service_sans_avance_de_demarrage(): void
    {
        $c = $this->contract('SERVICE', 1000, 20);
        $saisie = $this->user(['can_manage_payments' => true]);
        $this->as($saisie)->postJson("/api/contracts/{$c}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2026-02-25', 'gross_amount' => '400'])->assertOk();
        $d = $this->as($saisie)->getJson("/api/decomptes?contract_id={$c}")->json();
        $this->assertEquals(0, $d['selected']['advanceReceived']);
        $this->assertTrue($d['selected']['isService']);
        $this->assertEquals(0, $d['rows'][0]['advance']);
        $this->assertEquals(380, $d['rows'][0]['net']);
    }

    public function test_le_decompte_a_valider_reste_dans_l_application_sans_e_mail(): void
    {
        $c = $this->contract();
        $chef = $this->user(['can_validate_decomptes' => true, 'email' => 'chef.cellule@example.test', 'full_name' => 'Chef de cellule']);
        $saisie = $this->user(['can_manage_payments' => true, 'full_name' => 'Jorcelin MIKADO']);
        // Un ancien abonné à la catégorie ne reçoit rien non plus.
        DB::table('alert_recipients')->insert(['project_id' => $this->prise->id, 'email' => 'personnel@example.test', 'categories' => 'DECOMPTE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['Chef de cellule'], $this->as($saisie)->getJson("/api/decomptes?contract_id={$c}")->assertOk()->json('validators'));
        $res = $this->as($saisie)->postJson("/api/contracts/{$c}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2026-02-25', 'gross_amount' => '1 000']);
        $id = $res->assertOk()->json('id');
        $this->assertStringContainsString('chef de cellule infrastructures', $res->json('message'));
        $this->assertSame(1, $this->as($chef)->getJson('/api/notifications')->json('unread'));

        // Même après plusieurs jours d'attente : aucun message prévu ni envoyé, la notification reste à traiter.
        DB::table('contract_decomptes')->where('id', $id)->update(['created_at' => now()->subDays(3)]);
        $this->assertSame([], array_values(array_filter(AlertEngine::run($this->prise->id, true), fn ($m) => ($m['category'] ?? '') === 'DECOMPTE')));
        AlertEngine::run($this->prise->id, false);
        $this->assertSame(0, DB::table('alert_dispatches')->where('category', 'DECOMPTE')->count());
        $this->assertSame(1, $this->as($chef)->getJson('/api/notifications')->json('unread'));

        // La décision clôt la notification.
        $this->as($chef)->postJson("/api/decomptes/{$id}/decision", ['decision' => 'VALIDE'])->assertOk();
        $this->assertSame(0, $this->as($chef)->getJson('/api/notifications')->json('unread'));
    }

    public function test_la_validation_passe_du_comptable_au_chef_de_cellule(): void
    {
        $poste = fn ($name) => DB::table('positions')->insertGetId(['project_id' => $this->prise->id, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $comptable = $this->user(['can_validate_decomptes' => true, 'position_id' => $poste('COMPTABLE')]);
        $assistant = $this->user(['can_validate_decomptes' => true, 'position_id' => $poste('ASSISTANT COMPTABLE')]);
        $chef = $this->user(['position_id' => $poste('CHEF DE CELLULE INFRASTRUCTURES (AEP)')]);

        (require base_path('database/migrations/2026_11_03_000001_recipient_position_and_decompte_validator.php'))->up();

        $flag = fn ($u) => (bool) DB::table('users')->where('id', $u->id)->value('can_validate_decomptes');
        $this->assertSame([false, true, true], [$flag($comptable), $flag($assistant), $flag($chef)]);
    }
}
