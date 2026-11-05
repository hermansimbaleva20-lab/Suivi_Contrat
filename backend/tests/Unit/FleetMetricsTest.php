<?php

namespace Tests\Unit;

use App\Support\FleetMetrics;
use PHPUnit\Framework\TestCase;

/** Charroi : estimation du carburant, réservoir, compteur, consommation moyenne, entretien, assurance, alertes. */
class FleetMetricsTest extends TestCase
{
    private const VEHICLE = [
        'kind' => 'VEHICULE', 'tank_capacity' => 120.0, 'consumption_rate' => 0.2, 'fuel_price' => 1.37, 'fuel_alert_level' => 30.0,
        'maintenance_interval' => 3000, 'maintenance_warning' => 2500, 'initial_meter' => 42000.0,
    ];
    private const GENERATOR = [
        'kind' => 'GROUPE', 'tank_capacity' => 200.0, 'consumption_rate' => 4.0, 'fuel_price' => 1.37, 'fuel_alert_level' => null,
        'maintenance_interval' => 100, 'maintenance_warning' => 75, 'initial_meter' => 0.0,
    ];

    private static function log(int $id, string $date, float $start, float $end, array $extra = []): array
    {
        return $extra + ['id' => $id, 'date' => $date, 'meter_start' => $start, 'meter_end' => $end, 'fuel_level_before' => null,
            'fuel_purchased' => null, 'fuel_price' => null, 'lubricant' => null, 'cancelled' => false, 'authorized' => false];
    }

    public function test_methode_d_estimation_du_cahier_des_charges(): void
    {
        // 547 km × 0,2 L/km ≈ 109 L ; × 1,37 USD/L.
        $c = FleetMetrics::line(self::VEHICLE, self::log(1, '2026-10-01', 42000, 42547));
        $this->assertSame(547.0, $c['distance']);
        $this->assertSame(109.4, $c['estimatedLitres']);
        $this->assertSame(149.88, $c['estimatedCost']);
        // Prix saisi manuellement sur la ligne.
        $this->assertSame(164.1, FleetMetrics::line(self::VEHICLE, self::log(1, '2026-10-01', 0, 547, ['fuel_price' => 1.5]))['estimatedCost']);
    }

    public function test_niveau_du_reservoir_reporte_de_course_en_course_et_alerte_au_quart(): void
    {
        $logs = [
            self::log(1, '2026-10-01', 42000, 42100, ['fuel_level_before' => 120.0]), // plein − 20 L = 100
            self::log(2, '2026-10-02', 42100, 42150, ['fuel_purchased' => 30.0]), // 100 + 30 − 10 = 120 (plafonné)
            self::log(3, '2026-10-03', 42150, 42600), // 120 − 90 = 30
        ];
        $this->assertSame([1 => 100.0, 2 => 120.0, 3 => 30.0], FleetMetrics::levels(self::VEHICLE, $logs));
        $level = FleetMetrics::fuelLevel(self::VEHICLE, $logs);
        $alerts = FleetMetrics::alerts(self::VEHICLE, $level, FleetMetrics::maintenance(self::VEHICLE, 42600, []), null, '2026-10-03', '2026-10-04');
        $this->assertSame('FUEL', $alerts[0]['code']);
        $this->assertNull(FleetMetrics::fuelLevel(self::VEHICLE, [self::log(1, '2026-10-01', 0, 10)]));
    }

    public function test_compteur_qui_recule_ou_chevauche(): void
    {
        $ok = [self::log(1, '2026-10-01', 100, 150), self::log(2, '2026-10-02', 160, 200)]; // écart admis : course non saisie
        $this->assertNull(FleetMetrics::firstMeterConflict($ok));
        $overlap = array_merge($ok, [self::log(3, '2026-10-03', 190, 220)]);
        $this->assertSame('OVERLAP', FleetMetrics::firstMeterConflict($overlap)['type']);
        // Saisie antidatée qui chevauche une course ultérieure.
        $backdated = array_merge($ok, [self::log(9, '2026-10-01', 150, 170)]);
        $c = FleetMetrics::firstMeterConflict($backdated);
        $this->assertSame(2, $c['log']['id']);
        // Une ligne annulée n'est plus prise en compte.
        $this->assertNull(FleetMetrics::firstMeterConflict(array_merge($ok, [self::log(3, '2026-10-03', 190, 220, ['cancelled' => true])])));
    }

    public function test_totaux_mensuels_du_carnet_saisies_autorisees_seulement(): void
    {
        $logs = [
            self::log(1, '2026-10-01', 0, 300, ['fuel_purchased' => 50.0, 'lubricant' => 1.0, 'authorized' => true]),
            self::log(2, '2026-10-05', 300, 547, ['fuel_purchased' => 59.4, 'fuel_price' => 1.5, 'authorized' => true]),
            self::log(3, '2026-10-06', 547, 900, ['cancelled' => true, 'fuel_purchased' => 99.0, 'authorized' => true]),
            self::log(4, '2026-10-07', 900, 1000, ['fuel_purchased' => 30.0]), // en attente du visa
        ];
        $t = FleetMetrics::totals(self::VEHICLE, $logs);
        $this->assertSame(2, $t['entries']);
        $this->assertSame(547.0, $t['distance']);
        $this->assertSame(109.4, $t['litresPurchased']);
        $this->assertSame(157.6, $t['purchaseCost']); // 50 × 1,37 + 59,4 × 1,5
        $this->assertSame(20.0, $t['per100Km']);
        $this->assertNull($t['perHour']);
        // La saisie non autorisée est comptée à part, hors totaux.
        $this->assertSame(1, $t['pending']);
        $this->assertSame(100.0, $t['pendingDistance']);
        $this->assertSame(30.0, $t['pendingLitres']);
        // Somme de deux totaux (rapport par unité).
        $s = FleetMetrics::sum('VEHICULE', [$t, $t]);
        $this->assertSame(1094.0, $s['distance']);
        $this->assertSame(20.0, $s['per100Km']);
        $this->assertSame(2, $s['pending']);
    }

    public function test_groupe_electrogene_en_heures(): void
    {
        $t = FleetMetrics::totals(self::GENERATOR, [self::log(1, '2026-10-01', 10, 30, ['fuel_purchased' => 90.0, 'authorized' => true])]);
        $this->assertSame(20.0, $t['distance']);
        $this->assertSame(4.5, $t['perHour']);
        $this->assertNull($t['per100Km']);
        $this->assertSame('SOON', FleetMetrics::maintenance(self::GENERATOR, 80, [])['level']);
        $this->assertSame('DUE', FleetMetrics::maintenance(self::GENERATOR, 104, [])['level']);
    }

    public function test_entretien_avertissement_puis_echeance_puis_remise_a_zero(): void
    {
        // Sans programmation : à un intervalle de l'entrée au parc (42 000 + 3 000), alerte aux trois quarts.
        $this->assertSame('OK', FleetMetrics::maintenance(self::VEHICLE, 44249, [])['level']);
        $this->assertSame('SOON', FleetMetrics::maintenance(self::VEHICLE, 44250, [])['level']);
        $m = FleetMetrics::maintenance(self::VEHICLE, 45100, []);
        $this->assertSame('DUE', $m['level']);
        $this->assertSame(45000.0, $m['nextMeter']);
        $after = FleetMetrics::maintenance(self::VEHICLE, 45100, [['reading' => 45050.0, 'next' => null]]);
        $this->assertSame('OK', $after['level']);
        $this->assertSame(48050.0, $after['nextMeter']);
    }

    public function test_entretien_programme_alerte_aux_trois_quarts_du_parcours(): void
    {
        // Dernier à 40 000 km, prochain à 44 000 km : l'alerte commence à 43 000 km.
        $asset = ['maintenance_last_meter' => 40000.0, 'maintenance_next_meter' => 44000.0] + self::VEHICLE;
        $m = FleetMetrics::maintenance($asset, 42999, []);
        $this->assertSame(['OK', 43000.0, 44000.0, 1001.0, 'PLAN', true], [$m['level'], $m['warnMeter'], $m['nextMeter'], $m['remaining'], $m['source'], $m['planned']]);

        $soon = FleetMetrics::maintenance($asset, 43000, []);
        $this->assertSame(['SOON', 75.0, 1000.0], [$soon['level'], $soon['consumedPercent'], $soon['remaining']]);
        $alert = FleetMetrics::alerts(self::VEHICLE, 80.0, $soon, null, '2026-10-28', '2026-10-28')[0];
        $this->assertSame(['warning', 'MAINTENANCE'], [$alert['level'], $alert['code']]);
        $this->assertStringContainsString('il reste 1 000 km (75 % du parcours consommé)', $alert['text']);
        // Chaque avancée du compteur rapproche l'échéance affichée.
        $this->assertStringContainsString('il reste 200 km', FleetMetrics::alerts(self::VEHICLE, 80.0, FleetMetrics::maintenance($asset, 43800, []), null, '2026-10-28', '2026-10-28')[0]['text']);

        $due = FleetMetrics::maintenance($asset, 44150, []);
        $this->assertSame('DUE', $due['level']);
        $alert = FleetMetrics::alerts(self::VEHICLE, 80.0, $due, null, '2026-10-28', '2026-10-28')[0];
        $this->assertSame('danger', $alert['level']);
        $this->assertStringContainsString('prévu à 44 000 km, dépassé de 150 km', $alert['text']);

        // L'entretien enregistré repart de son compteur : l'alerte s'arrête.
        $done = FleetMetrics::maintenance(self::VEHICLE, 44150, [['reading' => 44100.0, 'next' => 47100.0]]);
        $this->assertSame(['OK', 44100.0, 47100.0, 46350.0, 'RECORD'], [$done['level'], $done['lastMeter'], $done['nextMeter'], $done['warnMeter'], $done['source']]);
        $this->assertSame([], array_filter(FleetMetrics::alerts(self::VEHICLE, 80.0, $done, null, '2026-10-28', '2026-10-28'), fn ($a) => $a['code'] === 'MAINTENANCE'));

        // Le dernier entretien est le plus haut compteur, même saisi plus tard ; une programmation plus récente l'emporte.
        $records = [['reading' => 44100.0, 'next' => 47100.0], ['reading' => 41000.0, 'next' => 43000.0]];
        $this->assertSame(47100.0, FleetMetrics::maintenance(self::VEHICLE, 44150, $records)['nextMeter']);
        $recent = FleetMetrics::maintenance(['maintenance_last_meter' => 45000.0, 'maintenance_next_meter' => 50000.0] + self::VEHICLE, 45100, $records);
        $this->assertSame(['PLAN', 50000.0], [$recent['source'], $recent['nextMeter']]);
    }

    public function test_assurance_et_carnet_non_tenu(): void
    {
        $this->assertSame('NONE', FleetMetrics::insurance([], '2026-10-28')['level']);
        $this->assertSame('EXPIRED', FleetMetrics::insurance([['start_date' => '2025-10-01', 'end_date' => '2026-10-27']], '2026-10-28')['level']);
        $this->assertSame('SOON', FleetMetrics::insurance([['start_date' => '2025-11-15', 'end_date' => '2026-11-14']], '2026-10-28')['level']);
        $this->assertSame('OK', FleetMetrics::insurance([['start_date' => '2025-10-01', 'end_date' => '2026-10-27'], ['start_date' => '2026-10-28', 'end_date' => '2027-10-27']], '2026-10-28')['level']);

        $ok = FleetMetrics::maintenance(self::VEHICLE, 42000, []);
        $codes = fn ($a) => array_column($a, 'code');
        $this->assertSame([], $codes(FleetMetrics::alerts(self::VEHICLE, 80.0, $ok, null, '2026-10-21', '2026-10-28')));
        $this->assertSame(['STALE'], $codes(FleetMetrics::alerts(self::VEHICLE, 80.0, $ok, null, '2026-10-20', '2026-10-28')));
        $this->assertSame(['STALE'], $codes(FleetMetrics::alerts(self::VEHICLE, null, $ok, null, null, '2026-10-28')));
    }
}
