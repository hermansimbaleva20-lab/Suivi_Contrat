<?php

namespace Tests\Unit;

use App\Support\DecompteLedger;
use App\Support\GoodsProcedure;
use App\Support\Metrics;
use PHPUnit\Framework\TestCase;

/** Règles du document « Procédure pour marché de biens » : taux, échéancier, pénalité de retard. */
class GoodsProcedureTest extends TestCase
{
    public function test_taux_du_document(): void
    {
        $expected = [
            [GoodsProcedure::BAD, GoodsProcedure::FOREIGN, 10.0, [80.0, 10.0]],
            [GoodsProcedure::BAD, GoodsProcedure::LOCAL, 10.0, [80.0, 10.0]],
            [GoodsProcedure::NATIONAL, GoodsProcedure::FOREIGN, 15.0, [65.0, 20.0]],
            [GoodsProcedure::NATIONAL, GoodsProcedure::LOCAL, 20.0, [80.0]],
        ];
        foreach ($expected as [$procedure, $origin, $advance, $tranches]) {
            $d = GoodsProcedure::defaults($procedure, $origin);
            $this->assertSame($advance, $d['advanceRate']);
            $this->assertSame($tranches, array_column($d['tranches'], 'rate'));
            $this->assertSame(100.0, $advance + array_sum($tranches));
        }
        $rules = GoodsProcedure::catalog()['rules'];
        $this->assertSame([10.0, null, null], [$rules['BAD']['guaranteeRate'], $rules['BAD']['penaltyRate'], $rules['BAD']['penaltyCap']]);
        $this->assertSame([5.0, 0.05, 10.0], [$rules['NATIONALE']['guaranteeRate'], $rules['NATIONALE']['penaltyRate'], $rules['NATIONALE']['penaltyCap']]);
    }

    public function test_seules_les_procedures_bad_et_nationale_changent_les_regles(): void
    {
        $this->assertTrue(GoodsProcedure::governs('BAD'));
        $this->assertTrue(GoodsProcedure::governs('NATIONALE'));
        $this->assertFalse(GoodsProcedure::governs('FOURNITURES_BUREAU'));
        $this->assertFalse(GoodsProcedure::governs(null));
        $this->assertTrue(GoodsProcedure::renewable('BIENS', 'FOURNITURES_BUREAU'));
        $this->assertTrue(GoodsProcedure::renewable('BIENS', null));
        $this->assertFalse(GoodsProcedure::renewable('BIENS', 'BAD'));
        $this->assertFalse(GoodsProcedure::renewable('BIENS', 'NATIONALE'));
        $this->assertFalse(GoodsProcedure::renewable('TRAVAUX', null));
        $this->assertNull(GoodsProcedure::describe('FOURNITURES_BUREAU', null));
        $this->assertTrue(GoodsProcedure::stockOnly('FOURNITURES_BUREAU'));
        $this->assertFalse(GoodsProcedure::stockOnly('NATIONALE'));
        $this->assertFalse(GoodsProcedure::stockOnly(null));
    }

    public function test_echeancier_modifiable_mais_toujours_a_100(): void
    {
        [$rows, $error] = GoodsProcedure::schedule('BAD', 'LOCAL', [], 10.0);
        $this->assertNull($error);
        $this->assertSame([80.0, 10.0], array_column($rows, 'rate'));

        [$rows, $error] = GoodsProcedure::schedule('BAD', 'LOCAL', [['key' => 'LIVRAISON', 'rate' => '75,5'], ['key' => 'ACCEPTATION', 'rate' => '4.5']], 20.0);
        $this->assertNull($error);
        $this->assertSame([75.5, 4.5], array_column($rows, 'rate'));

        $this->assertStringContainsString('100 %', GoodsProcedure::schedule('BAD', 'LOCAL', [], 15.0)[1]);
        $this->assertStringContainsString('compris entre', GoodsProcedure::schedule('BAD', 'LOCAL', [['key' => 'LIVRAISON', 'rate' => 'abc']], 10.0)[1]);
    }

    public function test_penalite_par_semaine_complete_et_plafonnee(): void
    {
        $p = fn ($reception) => GoodsProcedure::penalty(100000.0, 0.05, 10.0, '2026-06-01', $reception);
        $this->assertSame(['weeks' => 0, 'amount' => 0.0], $p('2026-05-20')); // en avance
        $this->assertSame(['weeks' => 0, 'amount' => 0.0], $p('2026-06-01')); // à la date
        $this->assertSame(['weeks' => 0, 'amount' => 0.0], $p('2026-06-07')); // semaine incomplète
        $this->assertSame(['weeks' => 1, 'amount' => 50.0], $p('2026-06-08'));
        $this->assertSame(['weeks' => 10, 'amount' => 500.0], $p('2026-08-10'));
        // 0,05 % × 400 semaines = 20 %, ramené au plafond de 10 %.
        $this->assertSame(['weeks' => 400, 'amount' => 10000.0], GoodsProcedure::penalty(100000.0, 0.05, 10.0, '2020-01-01', '2027-09-01'));
        $this->assertSame(['weeks' => 0, 'amount' => 0.0], GoodsProcedure::penalty(100000.0, null, null, '2026-06-01', '2026-08-10')); // procédure sans pénalité
    }

    public function test_la_penalite_reduit_le_montant_paye_et_solde_le_contrat(): void
    {
        $line = fn ($no, $gross, $penalty = 0) => ['id' => $no, 'line_no' => $no, 'gross_amount' => $gross, 'advance_rate' => 0.2, 'guarantee_rate' => 0.05, 'status' => DecompteLedger::VALID, 'penalty_amount' => $penalty];
        $rows = DecompteLedger::rows(1000.0, 200.0, [$line(1, 400.0), $line(2, 600.0, 30.0)]);
        $this->assertSame([0.0, 80.0, 20.0, 300.0], [$rows[0]['penalty'], $rows[0]['advance'], $rows[0]['guarantee'], $rows[0]['net']]);
        $this->assertSame([30.0, 120.0, 30.0, 420.0], [$rows[1]['penalty'], $rows[1]['advance'], $rows[1]['guarantee'], $rows[1]['net']]);
        // Il ne reste que la retenue de garantie (50), à reverser : la pénalité n'est plus due par le projet.
        $this->assertSame(50.0, $rows[1]['contractBalance']);
        $this->assertSame(30.0, DecompteLedger::totals($rows)['penalty']);

        // Une pénalité ne rend jamais le montant payé négatif.
        $rows = DecompteLedger::rows(1000.0, 0.0, [['id' => 1, 'line_no' => 1, 'gross_amount' => 100.0, 'advance_rate' => 0.0, 'guarantee_rate' => 0.05, 'status' => DecompteLedger::VALID, 'penalty_amount' => 500.0]]);
        $this->assertSame([95.0, 0.0], [$rows[0]['penalty'], $rows[0]['net']]);
    }

    public function test_avance_comptee_une_fois_payee_seulement(): void
    {
        $base = ['amount' => 1000.0, 'renewed_amount' => 0.0, 'initial_rate' => 10.0];
        $this->assertSame(100.0, Metrics::initialFinancing($base)); // règle habituelle

        $bad = $base + ['goods_procedure' => 'BAD'];
        $this->assertSame(0.0, Metrics::initialFinancing($bad + ['advance' => null]));
        $this->assertSame(0.0, Metrics::initialFinancing($bad + ['advance' => ['status' => 'DEMANDEE', 'amount' => 100.0, 'paid_date' => null]]));
        $paid = $bad + ['advance' => ['status' => 'PAYEE', 'amount' => 100.0, 'paid_date' => '2026-03-10']];
        $this->assertSame(100.0, Metrics::initialFinancing($paid));
        $this->assertSame(100.0, Metrics::initialFinancing($paid, '2026-03-10'));
        $this->assertSame(0.0, Metrics::initialFinancing($paid, '2026-03-09')); // état arrêté avant le paiement
    }

    public function test_le_temps_consomme_repart_de_la_nouvelle_periode(): void
    {
        $c = ['start_date' => '2024-01-01', 'end_date' => '2027-01-01', 'renewals' => [['period_start' => '2026-01-01']]];
        $this->assertSame(50.0, round(Metrics::elapsed($c, '2026-07-02'), 0));
        // Une date antérieure au renouvellement reste calculée sur la période d'origine.
        $this->assertSame(33.0, round(Metrics::elapsed($c, '2025-01-01'), 0));
    }

    public function test_un_contrat_de_fournitures_de_bureau_n_est_jamais_en_retard(): void
    {
        $c = ['goods_procedure' => 'FOURNITURES_BUREAU', 'terminated' => false, 'termination_date' => null, 'completed' => false, 'start_date' => '2024-01-01', 'end_date' => '2026-01-01', 'sites' => [], 'entries' => []];
        $this->assertSame('Normal', Metrics::status($c, '2025-12-01'));
        $this->assertSame('Terminé', Metrics::status($c, '2026-02-01'));
        $this->assertSame('Résilié', Metrics::status(['terminated' => true] + $c, '2025-12-01'));
        $this->assertSame('Retard', Metrics::status(['goods_procedure' => null] + $c, '2026-02-01'));
    }
}
