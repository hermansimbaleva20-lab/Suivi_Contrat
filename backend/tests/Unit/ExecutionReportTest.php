<?php

namespace Tests\Unit;

use App\Support\ExecutionReport;
use PHPUnit\Framework\TestCase;

/** État d'exécution : catégories puis provinces, ouvrages terminés, observations rédigées à partir des chiffres. */
class ExecutionReportTest extends TestCase
{
    private static function contract(int $id, string $number, string $category, string $province, float $amount, array $sites, array $entries = [], array $extra = []): array
    {
        foreach ($sites as $i => $s) $sites[$i] += ['province' => $province, 'description' => '', 'entries' => array_values(array_filter($entries, fn ($e) => $e['site_id'] === $s['id']))];
        return $extra + [
            'id' => $id, 'number' => $number, 'title' => '', 'activity' => "Activité {$number}", 'company' => 'SZTC', 'category' => $category, 'province' => $province,
            'signature_date' => '2025-01-22', 'start_date' => '2025-01-22', 'end_date' => '2027-01-22', 'duration_months' => 24, 'amount' => $amount, 'initial_rate' => 0.0,
            'installation_cost' => null, 'completed' => false, 'terminated' => false, 'termination_date' => null, 'termination_reason' => '',
            'sites' => $sites, 'entries' => $entries, 'payments' => [], 'amendments' => [], 'renewals' => [], 'guarantee' => null, 'goods_procedure' => null, 'advance' => null, 'penalties' => 0.0,
        ];
    }

    private static function entry(int $id, int $site, float $p, string $realization = '', string $date = '2026-06-30'): array
    {
        return ['id' => $id, 'site_id' => $site, 'installation' => false, 'date' => $date, 'progress' => $p, 'paid' => 0.0, 'realization' => $realization, 'observation' => ''];
    }

    public function test_matrice_par_categorie_et_province(): void
    {
        $works = self::contract(1, '068', 'TRAVAUX', 'Kasaï-Oriental', 300000, [
            ['id' => 11, 'name' => 'Tshilundu', 'work_type' => 'Système d’AEP', 'amount' => 200000],
            ['id' => 12, 'name' => 'Mukeba', 'work_type' => 'Système d’AEP', 'amount' => 100000],
        ], [self::entry(1, 11, 100, 'Forage productif, CE achevé'), self::entry(2, 12, 30, 'Premier forage négatif')]);
        $school = self::contract(2, '007', 'TRAVAUX', 'Kasaï-Central', 100000, [['id' => 21, 'name' => 'EP Dikombo', 'work_type' => 'École primaire', 'amount' => 100000]], [self::entry(3, 21, 100)]);
        $service = self::contract(3, '101', 'SERVICE', 'Kasaï-Central', 50000, [['id' => 31, 'name' => 'Bureau', 'work_type' => 'Gardiennage', 'amount' => 50000]]);

        $m = ExecutionReport::build([$works, $service, $school], '2026-08-31');
        $this->assertSame(['Catégorie Travaux', 'Catégorie Services'], array_column($m['categories'], 'label'));
        $travaux = $m['categories'][0];
        // Provinces par ordre alphabétique ; ouvrages terminés par type.
        $this->assertSame(['Kasaï-Central', 'Kasaï-Oriental'], array_column($travaux['provinces'], 'province'));
        $this->assertSame([['type' => 'Système d’AEP', 'count' => 1]], $travaux['provinces'][1]['completed']);
        $this->assertSame([['type' => 'École primaire', 'count' => 1], ['type' => 'Système d’AEP', 'count' => 1]], $m['completed']);

        // Contrat 068 : 100 % × 2/3 + 30 % × 1/3 = 76,67 % ; site par site, la dernière situation.
        $row = $travaux['provinces'][1]['rows'][0];
        $this->assertEqualsWithDelta(76.67, $row['progress'], 0.01);
        $this->assertSame(['Forage productif, CE achevé', 'Premier forage négatif'], array_column($row['sites'], 'situation'));
        $this->assertEquals([100, 30], array_column($row['sites'], 'progress'));
        // Taux de la catégorie pondéré par le coût : (300 000 × 76,67 + 100 000 × 100) / 400 000.
        $this->assertEqualsWithDelta(82.5, $travaux['totals']['progress'], 0.01);
        $this->assertEquals(400000, $travaux['totals']['amount']);
    }

    public function test_observation_du_retard(): void
    {
        // 586 jours sur 730 consommés (80,3 %) pour 30 % d'avancement : retard d'environ 50 points.
        $c = self::contract(1, '069', 'TRAVAUX', 'Sankuru', 100000, [['id' => 1, 'name' => 'Djalo', 'work_type' => 'Système d’AEP', 'amount' => 100000]], [self::entry(1, 1, 30)]);
        $row = ExecutionReport::contract($c, '2026-08-31');
        $this->assertSame('À ce jour, le taux d’exécution devrait être de 80 %, soit un retard de 50 %.', $row['observation']);
        $this->assertSame(['Équateur', 'Haut-Katanga', 'Kasaï'], (function () { $p = ['Kasaï', 'Haut-Katanga', 'Équateur']; usort($p, [ExecutionReport::class, 'compare']); return $p; })());
        $this->assertSame('Exécution achevée à 100 %.', ExecutionReport::observation($c, 100, 80, '2026-08-31', ''));
        $this->assertSame('Exécution achevée à 100 %.', ExecutionReport::observation($c, 99.999999, 100, '2026-08-31', ''));
        $this->assertSame('Contrat résilié le 21/07/2025. Prix trop élevés', ExecutionReport::observation(['terminated' => true, 'termination_date' => '2025-07-21', 'termination_reason' => 'Prix trop élevés'], 40, 90, '2026-08-31', ''));
    }
}
