<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\ContractSynthesis;
use App\Support\Dates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Synthèse des contrats sur une période : indicateurs arrêtés à la fin de la période, flux de la période, constats. */
class ContractSynthesisApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private ProjectPhase $phase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        $this->phase = ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 2, 'name' => 'Phase 2', 'is_current' => true, 'is_archived' => false]);
    }

    private function viewer(array $attrs = ['can_view_reports' => true]): User
    {
        $u = new User();
        $u->forceFill(array_merge(['project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent Suivi', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false], $attrs))->save();
        $this->actingAs($u, 'sanctum');
        return $u;
    }

    private function api()
    {
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    /** Contrat de 1 000 USD (avance 20 %), du 01/01/2026 au 31/12/2026. */
    private function contract(string $ref, array $extra = []): int
    {
        $co = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => "Entreprise {$ref}", 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('contracts')->insertGetId($extra + ['project_id' => $this->prise->id, 'phase_id' => $this->phase->id, 'company_id' => $co, 'reference' => $ref, 'category' => 'TRAVAUX',
            'amount' => 1000, 'initial_financing_rate' => 20, 'start_date' => '2026-01-01', 'signature_date' => '2026-01-01', 'end_date' => '2026-12-31', 'duration_months' => 12, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function progress(int $contract, string $date, float $value): void
    {
        $site = DB::table('contract_sites')->where('contract_id', $contract)->value('id')
            ?: DB::table('contract_sites')->insertGetId(['contract_id' => $contract, 'province' => 'Kasaï-Central', 'name' => 'Site', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('progress_entries')->insert(['contract_id' => $contract, 'contract_site_id' => $site, 'situation_date' => $date, 'progress' => $value, 'paid_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function period(array $extra = []): array
    {
        return $this->api()->getJson('/api/reports/synthesis?' . http_build_query($extra + ['type' => 'LIBRE', 'from' => '2026-04-01', 'to' => '2026-06-30']))->assertOk()->json();
    }

    public function test_indicateurs_arretes_a_la_fin_de_la_periode_et_flux_de_la_periode(): void
    {
        $this->viewer();
        $a = $this->contract('068/PRISE');
        $this->progress($a, '2026-03-15', 10);
        $this->progress($a, '2026-05-15', 15);
        $this->progress($a, '2026-08-01', 30); // après la période : ignoré
        DB::table('contract_payments')->insert([
            ['contract_id' => $a, 'date' => '2026-02-01', 'amount' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['contract_id' => $a, 'date' => '2026-05-01', 'amount' => 300, 'created_at' => now(), 'updated_at' => now()],
            ['contract_id' => $a, 'date' => '2026-09-01', 'amount' => 50, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('performance_guarantees')->insert(['project_id' => $this->prise->id, 'contract_id' => $a, 'company_id' => DB::table('contracts')->where('id', $a)->value('company_id'), 'bank_name' => 'Rawbank',
            'account_number' => 'GBE-68', 'rate' => 5, 'amount' => 50, 'start_date' => '2026-01-01', 'end_date' => '2026-06-01', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('contract_decomptes')->insert(['project_id' => $this->prise->id, 'contract_id' => $a, 'line_no' => 1, 'number' => 'DECOMPTE N°1', 'operation_date' => '2026-05-20', 'gross_amount' => 100,
            'advance_rate' => 0.2, 'guarantee_rate' => 0.05, 'status' => 'VALIDE', 'decision_date' => '2026-05-25', 'created_at' => now(), 'updated_at' => now()]);

        $d = $this->period();
        $this->assertSame(['from' => '2026-04-01', 'to' => '2026-06-30', 'asOf' => '2026-06-30', 'isOpen' => false], array_intersect_key($d['period'], array_flip(['from', 'to', 'asOf', 'isOpen'])));
        $r = $d['rows'][0];
        // Avancement cumulé à la fin de la période : 10 % le 15/03 et 15 % le 15/05, sans la saisie d'août.
        $this->assertEqualsWithDelta(25, $r['progress'], 0.01);
        $this->assertEqualsWithDelta(15, $r['progressGain'], 0.01);
        $this->assertEqualsWithDelta(49.6, $r['elapsed'], 0.5);
        $this->assertEqualsWithDelta(round($r['progress'] / $r['elapsed'], 2), $r['spi'], 0.001);
        $this->assertEquals(600, $r['paid']); // avance 200 + 100 + 300
        $this->assertEquals(300, $r['paidInPeriod']);
        $this->assertEquals(400, $r['remaining']);
        $this->assertTrue($r['overpaid']);
        $this->assertSame('Expirée', $r['guarantee']);
        $this->assertSame('GBE-68', $r['guaranteeNumber']);
        $this->assertEquals(75, $r['toPay']); // MONTANT PAYÉ : 100 − 20 − 5
        $this->assertEquals(100, $r['decomptesValidated']);
        $text = implode(' | ', array_column($d['findings'], 'text'));
        $this->assertStringContainsString('1 garantie de bonne exécution expirée : 068/PRISE', $text);
        $this->assertStringContainsString('Taux de paiement supérieur à l’avancement physique : 068/PRISE', $text);
        $this->assertStringContainsString('Décomptes validés restant à payer : 75,00 USD', $text);
        $this->assertSame('critical', $d['findings'][0]['level']);
    }

    public function test_perimetre_filtre_et_periodes(): void
    {
        $this->viewer();
        $this->contract('A-EN-COURS');
        $b = $this->contract('B-APRES', ['start_date' => '2026-09-01', 'signature_date' => '2026-09-01', 'end_date' => '2027-09-01']);
        $this->contract('C-RESILIE', ['termination_date' => '2026-02-01', 'termination_reason' => 'Motif de test']);

        // Hors période : signé après la fin, ou résilié avant le début.
        $this->assertSame(['A-EN-COURS'], array_column($this->period()['rows'], 'number'));
        $all = $this->period(['from' => '2026-01-01', 'to' => '2026-12-31']);
        $this->assertCount(3, $all['contracts']);

        $one = $this->period(['from' => '2026-09-15', 'to' => '2026-10-15', 'contract_id' => $b]);
        $this->assertSame(['B-APRES'], array_column($one['rows'], 'number'));
        $this->assertSame(1, $one['summary']['contracts']);

        $t = $this->api()->getJson('/api/reports/synthesis?type=TRIMESTRE&year=2026&index=2')->assertOk()->json('period');
        $this->assertSame(['2026-04-01', '2026-06-30', 'T2 2026'], [$t['from'], $t['to'], $t['label']]);
        $m = $this->api()->getJson('/api/reports/synthesis?type=MOIS&year=2026&index=2')->assertOk()->json('period');
        $this->assertSame(['2026-02-01', '2026-02-28', 'Février 2026'], [$m['from'], $m['to'], $m['label']]);

        // Période en cours : arrêtée à aujourd'hui ; dates invalides et période à venir refusées.
        $today = Dates::today();
        $open = $this->api()->getJson('/api/reports/synthesis?' . http_build_query(['type' => 'LIBRE', 'from' => $today, 'to' => Dates::addDays($today, 30)]))->assertOk()->json('period');
        $this->assertSame([$today, true], [$open['asOf'], $open['isOpen']]);
        $tomorrow = Dates::addDays($today, 1);
        $this->api()->getJson('/api/reports/synthesis?' . http_build_query(['type' => 'LIBRE', 'from' => $tomorrow, 'to' => Dates::addDays($today, 30)]))->assertStatus(422);
        $this->api()->getJson('/api/reports/synthesis?type=LIBRE&from=2026-06-01&to=2026-05-01')->assertStatus(422);
        $this->api()->getJson('/api/reports/synthesis?type=LIBRE')->assertStatus(422);
    }

    public function test_portefeuille_compte_les_statuts_sans_les_moyenner_et_acces(): void
    {
        $this->viewer();
        $late = $this->contract('LATE', ['end_date' => '2026-03-31']);
        $this->progress($late, '2026-02-01', 10);
        $fine = $this->contract('FINE');
        $this->progress($fine, '2026-04-10', 45);
        $done = $this->contract('DONE');
        $this->progress($done, '2026-04-10', 100);

        $d = $this->period();
        $this->assertSame(['LATE', 'FINE', 'DONE'], array_column($d['rows'], 'number')); // les retards d'abord
        $this->assertSame(['Retard' => 1, 'Vigilance' => 0, 'Normal' => 1, 'Terminé' => 1, 'Résilié' => 0], $d['summary']['statuses']);
        $this->assertSame(91, $d['rows'][0]['daysLate']);
        $this->assertEquals(3000, $d['summary']['amount']);
        $this->assertEqualsWithDelta(51.7, $d['summary']['progress'], 0.05);
        $this->assertStringContainsString('1 contrat est en retard.', $d['lead']);
        $this->assertStringContainsString('le portefeuille compte 3 contrats pour 3 000,00 USD', $d['lead']);

        $this->viewer(['can_view_reports' => false]);
        $this->api()->getJson('/api/reports/synthesis?type=TRIMESTRE&year=2026&index=2')->assertForbidden();
    }

    public function test_resume_sans_contrat(): void
    {
        $this->assertSame('Aucun contrat n’était actif sur cette période.', ContractSynthesis::lead([], ContractSynthesis::summary([]), '2026-06-30'));
        $this->assertSame([], ContractSynthesis::findings([], ContractSynthesis::summary([])));
    }
}
