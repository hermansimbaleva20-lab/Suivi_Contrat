<?php

namespace App\Support;

/**
 * Calculs du charroi et des groupes électrogènes, sur des tableaux (testés sans démarrer Laravel).
 * Compteur (« meter ») : km pour un véhicule, heures pour un groupe.
 *
 * Engin : ['kind', 'tank_capacity', 'consumption_rate', 'fuel_price', 'fuel_alert_level', 'maintenance_interval', 'maintenance_warning', 'initial_meter']
 * Ligne : ['id', 'date', 'meter_start', 'meter_end', 'fuel_level_before', 'fuel_purchased', 'fuel_price', 'lubricant', 'cancelled', 'authorized']
 *
 * Méthode retenue (cahier des charges du charroi, 28/10/2026) :
 *  - litres estimés = distance × taux (0,2 L/km par défaut) ; montant = litres × prix (1,37 USD/L par défaut, modifiable à la saisie) ;
 *  - consommation moyenne = litres achetés ÷ km × 100, comme au pied du carnet de bord papier ;
 *  - niveau du réservoir = niveau au départ + litres achetés − litres estimés, reporté de course en course ;
 *  - entretien : prochain entretien programmé au compteur ; alerte dès les trois quarts du parcours entre le dernier et le prochain,
 *    jusqu'à ce que l'entretien soit enregistré ;
 */
class FleetMetrics
{
    /** Part du parcours entre deux entretiens à partir de laquelle l'alerte commence. */
    public const MAINTENANCE_WARNING_SHARE = 0.75;

    public const VEHICULE = 'VEHICULE';
    public const GROUPE = 'GROUPE';

    /** Une saisie au carnet est attendue au moins chaque semaine (périodicité hebdomadaire retenue pour alléger la charge). */
    public const STALE_DAYS = 7;
    public const INSURANCE_NOTICE_DAYS = 30;

    public static function sort(array $logs): array
    {
        usort($logs, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: ($a['id'] <=> $b['id']));
        return $logs;
    }

    private static function active(array $logs): array
    {
        return array_values(array_filter(self::sort($logs), fn ($l) => empty($l['cancelled'])));
    }

    private static function price(array $asset, array $log): float
    {
        return (float) ($log['fuel_price'] ?? $asset['fuel_price']);
    }

    /** Colonnes calculées d'une ligne du carnet. */
    public static function line(array $asset, array $log): array
    {
        $distance = round((float) $log['meter_end'] - (float) $log['meter_start'], 1);
        $rate = $asset['consumption_rate'];
        $estimated = $rate === null ? null : round($distance * (float) $rate, 2);
        $price = self::price($asset, $log);
        $purchased = $log['fuel_purchased'] === null ? null : (float) $log['fuel_purchased'];
        return [
            'distance' => $distance,
            'estimatedLitres' => $estimated,
            'estimatedCost' => $estimated === null ? null : round($estimated * $price, 2),
            'purchaseCost' => $purchased === null ? null : round($purchased * $price, 2),
            'price' => $price,
        ];
    }

    private static function clampLevel(array $asset, float $level): float
    {
        return round(max(0.0, min((float) $asset['tank_capacity'], $level)), 2);
    }

    /** Niveau estimé après chaque ligne (null tant qu'aucun niveau de départ n'a été saisi), reporté de ligne en ligne. */
    public static function levels(array $asset, array $logs): array
    {
        $level = null;
        $out = [];
        foreach (self::active($logs) as $l) {
            if ($l['fuel_level_before'] !== null) $level = (float) $l['fuel_level_before'];
            if ($level !== null) {
                $level = self::clampLevel($asset, $level + (float) ($l['fuel_purchased'] ?? 0) - (float) (self::line($asset, $l)['estimatedLitres'] ?? 0));
            }
            $out[$l['id']] = $level;
        }
        return $out;
    }

    public static function fuelLevel(array $asset, array $logs): ?float
    {
        $levels = self::levels($asset, $logs);
        return $levels ? end($levels) : null;
    }

    /**
     * Premier défaut de compteur : arrivée inférieure au départ, ou départ inférieur à l'arrivée de la ligne précédente
     * (le compteur ne recule pas ; un écart vers le haut est admis : course non saisie). null si tout est cohérent.
     */
    public static function firstMeterConflict(array $logs): ?array
    {
        $prev = null;
        foreach (self::active($logs) as $l) {
            if ((float) $l['meter_end'] < (float) $l['meter_start']) return ['type' => 'ORDER', 'log' => $l, 'previous' => null];
            if ($prev && (float) $l['meter_start'] < (float) $prev['meter_end']) return ['type' => 'OVERLAP', 'log' => $l, 'previous' => $prev];
            $prev = $l;
        }
        return null;
    }

    public const TOTAL_KEYS = ['entries' => 0, 'distance' => 0.0, 'litresPurchased' => 0.0, 'purchaseCost' => 0.0, 'estimatedLitres' => 0.0, 'estimatedCost' => 0.0, 'lubricantEntries' => 0, 'pending' => 0, 'objected' => 0, 'pendingDistance' => 0.0, 'pendingLitres' => 0.0];
    public const ROUNDING = ['distance' => 1, 'litresPurchased' => 2, 'purchaseCost' => 2, 'estimatedLitres' => 2, 'estimatedCost' => 2, 'pendingDistance' => 1, 'pendingLitres' => 2];

    /**
     * Totaux du carnet : km (ou heures), litres achetés et estimés, montants, consommation moyenne.
     * Décision du 28/10/2026 : seules les saisies AUTORISÉES par le comptable ou le RAF entrent dans les totaux ; les autres
     * sont comptées à part (pending, pendingDistance, pendingLitres) jusqu'à leur autorisation ; une saisie objectée n'est
     * comptée que dans « objected ». Le lubrifiant est un texte (« PLEIN », « 2 L ») : on compte les appoints.
     */
    public static function totals(array $asset, array $logs): array
    {
        $t = self::TOTAL_KEYS;
        foreach (self::active($logs) as $l) {
            $c = self::line($asset, $l);
            if (!empty($l['objected'])) {
                $t['objected']++;
                continue;
            }
            if (empty($l['authorized'])) {
                $t['pending']++;
                $t['pendingDistance'] += $c['distance'];
                $t['pendingLitres'] += (float) ($l['fuel_purchased'] ?? 0);
                continue;
            }
            $t['entries']++;
            $t['distance'] += $c['distance'];
            $t['litresPurchased'] += (float) ($l['fuel_purchased'] ?? 0);
            $t['purchaseCost'] += (float) ($c['purchaseCost'] ?? 0);
            $t['estimatedLitres'] += (float) ($c['estimatedLitres'] ?? 0);
            $t['estimatedCost'] += (float) ($c['estimatedCost'] ?? 0);
            if (trim((string) ($l['lubricant'] ?? '')) !== '') $t['lubricantEntries']++;
        }
        return self::finish($asset['kind'], $t);
    }

    /** Arrondis et consommation moyenne d'un total (d'un engin ou cumulé sur plusieurs engins du même type). */
    public static function finish(string $kind, array $t): array
    {
        foreach (self::ROUNDING as $k => $d) $t[$k] = round($t[$k], $d);
        return $t + self::averages($kind, $t['distance'], $t['litresPurchased']);
    }

    /** Somme de plusieurs totaux du même type d'engin. */
    public static function sum(string $kind, array $totals): array
    {
        $t = self::TOTAL_KEYS;
        foreach ($totals as $x) foreach (array_keys(self::TOTAL_KEYS) as $k) $t[$k] += $x[$k];
        return self::finish($kind, $t);
    }

    /** Consommation moyenne : L/100 km pour un véhicule, L/h pour un groupe ; null sans distance ou sans achat. */
    public static function averages(string $kind, float $distance, float $litres): array
    {
        $ok = $distance > 0 && $litres > 0;
        return [
            'per100Km' => $kind === self::VEHICULE && $ok ? round($litres / $distance * 100, 2) : null,
            'perHour' => $kind === self::GROUPE && $ok ? round($litres / $distance, 2) : null,
        ];
    }

    public static function currentMeter(array $asset, array $logs, array $maintenanceReadings): float
    {
        $m = (float) $asset['initial_meter'];
        foreach (self::active($logs) as $l) $m = max($m, (float) $l['meter_end']);
        foreach ($maintenanceReadings as $r) $m = max($m, (float) $r);
        return round($m, 1);
    }

    /**
     * Prochain entretien (décision du 05/11/2026). Point de départ : le dernier entretien enregistré ou la programmation de
     * l'engin si elle est au moins aussi récente, sinon l'entrée au parc. Prochain : le compteur programmé, à défaut
     * dernier + intervalle. OK | SOON (trois quarts du parcours consommés) | DUE (compteur du prochain atteint).
     * Entretien : ['reading' => float, 'next' => ?float].
     */
    public static function maintenance(array $asset, float $currentMeter, array $maintenances): array
    {
        $last = (float) $asset['initial_meter'];
        $next = null;
        $source = 'INITIAL';
        foreach ($maintenances as $m) {
            if ($source === 'INITIAL' || (float) $m['reading'] >= $last) {
                $last = (float) $m['reading'];
                $next = $m['next'] ?? null;
                $source = 'RECORD';
            }
        }
        $plan = $asset['maintenance_last_meter'] ?? null;
        if ($plan !== null && ($source === 'INITIAL' || (float) $plan >= $last)) {
            $last = (float) $plan;
            $next = $asset['maintenance_next_meter'] ?? null;
            $source = 'PLAN';
        }
        $planned = $next !== null;
        $next = $planned ? (float) $next : $last + (int) $asset['maintenance_interval'];
        $span = max(1.0, $next - $last);
        $warnAt = $last + $span * self::MAINTENANCE_WARNING_SHARE;
        return [
            'lastMeter' => $last, 'since' => round(max(0.0, $currentMeter - $last), 1), 'nextMeter' => round($next, 1), 'warnMeter' => round($warnAt, 1), 'remaining' => round($next - $currentMeter, 1),
            'consumedPercent' => round(max(0.0, ($currentMeter - $last) / $span * 100), 1),
            'level' => $currentMeter >= $next ? 'DUE' : ($currentMeter >= $warnAt ? 'SOON' : 'OK'),
            'source' => $source, 'planned' => $planned, 'hasRecord' => (bool) $maintenances,
        ];
    }

    /** Assurance en cours : la police dont l'échéance est la plus lointaine. NONE | EXPIRED | SOON (≤ 30 jours) | OK. */
    public static function insurance(array $insurances, string $today): array
    {
        if (!$insurances) return ['level' => 'NONE', 'endDate' => null, 'daysLeft' => null];
        usort($insurances, fn ($a, $b) => strcmp($b['end_date'], $a['end_date']));
        $end = $insurances[0]['end_date'];
        $days = Dates::daysBetween($today, $end);
        $level = $end < $today ? 'EXPIRED' : ($days <= self::INSURANCE_NOTICE_DAYS ? 'SOON' : 'OK');
        return ['level' => $level, 'endDate' => $end, 'daysLeft' => $days, 'designation' => $insurances[0]['designation'] ?? null];
    }

    /** Alertes d'un engin, de la plus grave à la moins grave. */
    public static function alerts(array $asset, ?float $fuelLevel, array $maintenance, ?array $insurance, ?string $lastLogDate, string $today): array
    {
        $unit = $asset['kind'] === self::VEHICULE ? 'km' : 'h';
        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 1, ',', ' '), '0'), ',');
        $a = [];
        if ($maintenance['level'] === 'DUE') {
            $a[] = ['level' => 'danger', 'code' => 'MAINTENANCE', 'text' => 'Entretien dû : prévu à ' . $fmt($maintenance['nextMeter']) . " $unit, dépassé de " . $fmt(-$maintenance['remaining']) . " $unit."];
        } elseif ($maintenance['level'] === 'SOON') {
            $a[] = ['level' => 'warning', 'code' => 'MAINTENANCE', 'text' => 'Entretien prévu à ' . $fmt($maintenance['nextMeter']) . " $unit : il reste " . $fmt($maintenance['remaining']) . " $unit (" . $fmt($maintenance['consumedPercent']) . ' % du parcours consommé).'];
        }
        if ($asset['fuel_alert_level'] !== null && $fuelLevel !== null && $fuelLevel <= (float) $asset['fuel_alert_level']) {
            $a[] = ['level' => 'danger', 'code' => 'FUEL', 'text' => 'Réservoir bas : environ ' . $fmt($fuelLevel) . ' L estimés (alerte à ' . $fmt($asset['fuel_alert_level']) . ' L). Lancez la réquisition de carburant.'];
        }
        if ($insurance) {
            if ($insurance['level'] === 'EXPIRED') $a[] = ['level' => 'danger', 'code' => 'INSURANCE', 'text' => 'Assurance expirée depuis le ' . Dates::fr($insurance['endDate']) . '.'];
            elseif ($insurance['level'] === 'SOON') $a[] = ['level' => 'warning', 'code' => 'INSURANCE', 'text' => "Assurance à renouveler : échéance le " . Dates::fr($insurance['endDate']) . " (dans {$insurance['daysLeft']} jour(s))."];
            elseif ($insurance['level'] === 'NONE') $a[] = ['level' => 'warning', 'code' => 'INSURANCE', 'text' => 'Aucune assurance enregistrée.'];
        }
        if ($lastLogDate === null) {
            $a[] = ['level' => 'warning', 'code' => 'STALE', 'text' => 'Aucune saisie au carnet de bord.'];
        } elseif (($days = Dates::daysBetween($lastLogDate, $today)) > self::STALE_DAYS) {
            $a[] = ['level' => 'warning', 'code' => 'STALE', 'text' => "Carnet de bord non tenu depuis $days jours (dernière saisie le " . Dates::fr($lastLogDate) . ').'];
        }
        usort($a, fn ($x, $y) => ($x['level'] === 'danger' ? 0 : 1) <=> ($y['level'] === 'danger' ? 0 : 1));
        return $a;
    }
}
