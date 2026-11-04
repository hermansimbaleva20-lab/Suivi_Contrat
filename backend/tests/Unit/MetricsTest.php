<?php

namespace Tests\Unit;

use App\Support\Metrics;
use PHPUnit\Framework\TestCase;

/** Règles de calcul portées de ContractMetrics.cs / GuaranteeMetrics.cs : toute régression change les résultats de l'application. */
class MetricsTest extends TestCase
{
    private function contrat(array $surcharge = []): array
    {
        return array_merge([
            'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'amount' => 1000.0, 'initial_rate' => 0.0,
            'completed' => false, 'terminated' => false, 'termination_date' => null,
            'sites' => [], 'entries' => [], 'payments' => [], 'guarantee' => null,
        ], $surcharge);
    }

    private function saisie(string $date, float $progress, ?int $siteId = null, int $id = 1): array
    {
        return ['id' => $id, 'site_id' => $siteId, 'date' => $date, 'progress' => $progress];
    }

    public function test_avancement_cumule_par_site_plafonne_a_100_puis_moyenne_des_sites(): void
    {
        $c = $this->contrat(['sites' => [
            ['entries' => [$this->saisie('2025-02-01', 70, 1), $this->saisie('2025-03-01', 50, 1)]],
            ['entries' => [$this->saisie('2025-02-01', 20, 2)]],
            ['entries' => []],
        ]]);

        $this->assertSame(100.0, Metrics::siteProgress($c['sites'][0]));
        $this->assertEqualsWithDelta(40.0, Metrics::progress($c), 0.001);
        $this->assertEqualsWithDelta(30.0, Metrics::progress($c, '2025-02-15'), 0.001);
    }

    public function test_avancement_sans_site_est_la_moyenne_des_saisies(): void
    {
        $c = $this->contrat(['entries' => [$this->saisie('2025-02-01', 20), $this->saisie('2025-03-01', 60)]]);

        $this->assertEqualsWithDelta(40.0, Metrics::progress($c), 0.001);
        $this->assertSame(0.0, Metrics::progress($this->contrat()));
    }

    public function test_duree_consommee(): void
    {
        $this->assertSame(0.0, Metrics::elapsed($this->contrat(), '2024-12-01'));
        $this->assertEqualsWithDelta(50.0, Metrics::elapsed($this->contrat(['end_date' => '2025-01-11']), '2025-01-06'), 0.001);
        $this->assertSame(100.0, Metrics::elapsed($this->contrat(), '2026-06-01'));
        $this->assertSame(0.0, Metrics::elapsed($this->contrat(['start_date' => null]), '2025-06-01'));
    }

    public function test_statuts(): void
    {
        $base = $this->contrat(['end_date' => '2025-01-11']);
        $avec = fn (float $p) => array_merge($base, ['entries' => [$this->saisie('2025-01-01', $p)]]);

        // À mi-parcours (50 % consommé) : écart ≥ -20 Normal, ≥ -25 Vigilance, sinon Retard.
        $this->assertSame('Normal', Metrics::status($avec(30), '2025-01-06'));
        $this->assertSame('Vigilance', Metrics::status($avec(27), '2025-01-06'));
        $this->assertSame('Retard', Metrics::status($avec(20), '2025-01-06'));
        $this->assertSame('Retard', Metrics::status($avec(90), '2025-02-01'));
        $this->assertSame('Terminé', Metrics::status($avec(100), '2025-02-01'));
        $this->assertSame('Terminé', Metrics::status(array_merge($base, ['completed' => true]), '2025-01-06'));
        $this->assertSame('Résilié', Metrics::status(array_merge($base, ['terminated' => true, 'termination_date' => '2025-01-03']), '2025-01-06'));
        $this->assertNotSame('Résilié', Metrics::status(array_merge($base, ['terminated' => true, 'termination_date' => '2025-01-09']), '2025-01-06'));
    }

    public function test_paiements_et_avance_initiale(): void
    {
        $c = $this->contrat(['initial_rate' => 10.0, 'payments' => [
            ['date' => '2025-02-01', 'amount' => 200.0], ['date' => '2025-04-01', 'amount' => 300.0],
        ]]);

        $this->assertSame(100.0, Metrics::initialFinancing($c));
        $this->assertSame(500.0, Metrics::totalPaid($c));
        $this->assertSame(200.0, Metrics::totalPaid($c, '2025-03-01'));
        $this->assertEqualsWithDelta(60.0, Metrics::paymentPercent($c), 0.001);
        $this->assertSame(0.0, Metrics::paymentPercent($this->contrat(['amount' => 0.0])));
    }

    public function test_situation_de_la_garantie_et_prolongations(): void
    {
        $g = ['bank' => 'B', 'account' => 'A', 'rate' => 5, 'amount' => 50, 'start' => '2025-01-01', 'end' => '2025-01-11', 'extensions' => []];

        $this->assertSame('Non constituée', Metrics::guaranteeAt(null, '2025-01-01')['status']);
        $this->assertSame('À venir', Metrics::guaranteeAt($g, '2024-12-31')['status']);
        $this->assertSame('Valide', Metrics::guaranteeAt($g, '2025-01-06')['status']);
        $this->assertSame('Alerte >80%', Metrics::guaranteeAt($g, '2025-01-10')['status']);
        $this->assertSame('Expirée', Metrics::guaranteeAt($g, '2025-01-12')['status']);

        $g['extensions'] = [['type' => 'Prolongation', 'start' => '2025-01-12', 'end' => '2025-02-11']];
        $this->assertSame('2025-02-11', Metrics::guaranteeAt($g, '2025-01-20')['end']);
        $this->assertSame('2025-01-11', Metrics::guaranteeAt($g, '2025-01-05')['end']);
        $this->assertSame(1, Metrics::extCount($g, 'Prolongation'));
        $this->assertSame(0, Metrics::extCount($g, 'Prolongation', '2025-01-05'));
    }
}
