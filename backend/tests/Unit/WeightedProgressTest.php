<?php

namespace Tests\Unit;

use App\Support\Metrics;
use PHPUnit\Framework\TestCase;

/**
 * Avancement physique pondéré par les montants (décision du 10/11/2026) :
 * (taux installation × coût installation ÷ montant) + Σ (taux du site × montant du site ÷ montant).
 */
class WeightedProgressTest extends TestCase
{
    private static function entry(int $id, ?int $site, float $progress, string $date = '2026-10-01', bool $installation = false): array
    {
        return ['id' => $id, 'site_id' => $site, 'installation' => $installation, 'date' => $date, 'progress' => $progress, 'realization' => '', 'observation' => ''];
    }

    /** Installation 10 000 $, site 1 : 60 000 $, site 2 : 30 000 $ ; contrat de 100 000 $. */
    private static function contract(array $entries, ?float $installation = 10000, ?float $a1 = 60000, ?float $a2 = 30000): array
    {
        $sites = [['id' => 1, 'name' => 'Forage Kananga', 'amount' => $a1, 'entries' => []], ['id' => 2, 'name' => 'École Tshikapa', 'amount' => $a2, 'entries' => []]];
        foreach ($entries as $e) if ($e['site_id']) $sites[$e['site_id'] - 1]['entries'][] = $e;
        return ['category' => 'TRAVAUX', 'installation_cost' => $installation, 'amount' => (float) ($installation + $a1 + $a2), 'sites' => $sites, 'entries' => $entries];
    }

    public function test_formule_du_projet(): void
    {
        // Installation à 100 %, site 1 à 50 % (30 + 20), site 2 à 20 %.
        $c = self::contract([self::entry(1, null, 100, installation: true), self::entry(2, 1, 30), self::entry(3, 1, 20), self::entry(4, 2, 20)]);
        // 100 × 10 000 / 100 000 + 50 × 60 000 / 100 000 + 20 × 30 000 / 100 000 = 10 + 30 + 6 = 46 %.
        $this->assertEqualsWithDelta(46.0, Metrics::progress($c), 0.0001);
        $this->assertSame(['installation' => 10000.0, 'sites' => 90000.0, 'base' => 100000.0], Metrics::weighting($c));

        $b = Metrics::progressBreakdown($c);
        $this->assertTrue($b['weighted']);
        $this->assertSame(['INSTALLATION', 'SITE', 'SITE'], array_column($b['rows'], 'kind'));
        $this->assertEqualsWithDelta([10.0, 60.0, 30.0], array_column($b['rows'], 'weight'), 0.0001);
        $this->assertEqualsWithDelta([10.0, 30.0, 6.0], array_column($b['rows'], 'contribution'), 0.0001);
        $this->assertEqualsWithDelta(Metrics::progress($c), array_sum(array_column($b['rows'], 'contribution')), 0.0001);
    }

    public function test_a_une_date_donnee_et_plafond_par_composante(): void
    {
        $c = self::contract([self::entry(1, null, 60, '2026-09-01', true), self::entry(2, null, 60, '2026-10-15', true), self::entry(3, 1, 50, '2026-10-20')]);
        // Au 30/09 : seule la première situation de l'installation compte (60 % × 10 %).
        $this->assertEqualsWithDelta(6.0, Metrics::progress($c, '2026-09-30'), 0.0001);
        // Ensuite l'installation plafonne à 100 % : 10 + 50 × 60 % = 40 %.
        $this->assertEqualsWithDelta(100.0, Metrics::installationProgress($c), 0.0001);
        $this->assertEqualsWithDelta(40.0, Metrics::progress($c), 0.0001);
    }

    public function test_sans_installation_seuls_les_sites_pesent(): void
    {
        $c = self::contract([self::entry(1, 1, 100)], 0.0, 75000, 25000);
        $this->assertEqualsWithDelta(75.0, Metrics::progress($c), 0.0001);
        // L'installation figure toujours en premier, dans l'ordre de la formule ; sans coût, son terme vaut 0.
        $b = Metrics::progressBreakdown($c)['rows'];
        $this->assertSame(['INSTALLATION', 'SITE', 'SITE'], array_column($b, 'kind'));
        $this->assertEqualsWithDelta([0.0, 75.0, 0.0], array_column($b, 'contribution'), 0.0001);
    }

    public function test_ancien_contrat_sans_montant_par_site_reste_a_la_moyenne(): void
    {
        // Un site sans montant : pas de pondération, moyenne simple (100 + 0) / 2, et l'installation n'est pas comptée.
        $c = self::contract([self::entry(1, 1, 100), self::entry(2, null, 100, installation: true)], null, 60000, null);
        $this->assertNull(Metrics::weighting($c));
        $this->assertEqualsWithDelta(50.0, Metrics::progress($c), 0.0001);
        $b = Metrics::progressBreakdown($c);
        $this->assertFalse($b['weighted']);
        // Sans montant, la formule ne s'applique pas : aucun poids inventé (pas de « 1 / nombre de sites »), aucun résultat.
        $this->assertSame([null, null, null], array_column($b['rows'], 'weight'));
        $this->assertSame([null, null, null], array_column($b['rows'], 'contribution'));
        $this->assertEqualsWithDelta([100.0, 100.0, 0.0], array_column($b['rows'], 'progress'), 0.0001);
    }

    public function test_installation_reservee_aux_contrats_de_travaux(): void
    {
        // Contrat de SERVICE : un coût d'installation éventuel n'entre pas dans la formule, ni dans le détail du calcul.
        $c = ['category' => 'SERVICE'] + self::contract([self::entry(1, 1, 50), self::entry(2, null, 100, installation: true)], 10000, 60000, 40000);
        $this->assertFalse(Metrics::hasInstallation($c));
        $this->assertSame(['installation' => 0.0, 'sites' => 100000.0, 'base' => 100000.0], Metrics::weighting($c));
        $this->assertEqualsWithDelta(30.0, Metrics::progress($c), 0.0001);
        $this->assertSame(['SITE', 'SITE'], array_column(Metrics::progressBreakdown($c)['rows'], 'kind'));
    }

    public function test_contrat_sans_site_ignore_les_situations_d_installation(): void
    {
        $c = ['installation_cost' => null, 'sites' => [], 'entries' => [self::entry(1, null, 40), self::entry(2, null, 90, installation: true)]];
        $this->assertEqualsWithDelta(40.0, Metrics::progress($c), 0.0001);
    }
}
