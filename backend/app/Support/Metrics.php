<?php

namespace App\Support;

/**
 * Calculs contractuels (portage de ContractMetrics.cs et GuaranteeMetrics.cs).
 * Un contrat est un tableau normalisé par Loader : sites[] (avec entries[]), entries[], payments[], guarantee (avec extensions[]).
 */
class Metrics
{
    public static function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    public static function pct($v): float
    {
        return round((float) $v, 2);
    }

    // ------------------------------------------------------------------ Contrats
    public static function siteProgress(array $site, ?string $asOf = null): float
    {
        $sum = 0.0;
        foreach ($site['entries'] as $e) {
            if ($asOf === null || $e['date'] <= $asOf) $sum += (float) $e['progress'];
        }
        return self::clamp($sum, 0, 100);
    }

    /** Avancement du contrat = moyenne, sur tous ses sites, du cumul de chaque site (un site sans saisie compte pour 0 %). */
    public static function progress(array $c, ?string $asOf = null): float
    {
        if (!empty($c['sites'])) {
            $sum = 0.0;
            foreach ($c['sites'] as $s) $sum += self::siteProgress($s, $asOf);
            return self::clamp($sum / count($c['sites']), 0, 100);
        }
        $vals = [];
        foreach ($c['entries'] as $e) {
            if (!empty($e['site_id'])) continue;
            if ($asOf !== null && $e['date'] > $asOf) continue;
            $vals[] = self::clamp((float) $e['progress'], 0, 100);
        }
        return $vals ? self::clamp(array_sum($vals) / count($vals), 0, 100) : 0.0;
    }

    public static function elapsed(array $c, string $asOf): float
    {
        $start = $c['start_date'];
        $end = $c['end_date'];
        if (!$start || !$end) return 0.0;
        $total = Dates::daysBetween($start, $end);
        if ($total <= 0) return $asOf >= $end ? 100.0 : 0.0;
        if ($asOf < $start) return 0.0;
        return self::clamp(Dates::daysBetween($start, $asOf) / $total * 100, 0, 100);
    }

    public static function status(array $c, ?string $asOf = null): string
    {
        $day = $asOf ?? Dates::today();
        if (!empty($c['terminated']) && (empty($c['termination_date']) || $c['termination_date'] <= $day)) return 'Résilié';
        $progress = self::progress($c, $asOf);
        if (!empty($c['completed']) || $progress >= 100) return 'Terminé';
        if ($c['end_date'] && $day > $c['end_date']) return 'Retard';
        $gap = $progress - self::elapsed($c, $day);
        if ($gap >= -20) return 'Normal';
        if ($gap >= -25) return 'Vigilance';
        return 'Retard';
    }

    public static function initialFinancing(array $c, ?string $asOf = null): float
    {
        // Marché de biens en procédure BAD ou nationale : l'avance ne compte qu'une fois payée, à la demande de l'entreprise.
        if (GoodsProcedure::governs($c['goods_procedure'] ?? null)) {
            $a = $c['advance'] ?? null;
            return $a && $a['status'] === 'PAYEE' && ($asOf === null || $a['paid_date'] <= $asOf) ? (float) $a['amount'] : 0.0;
        }
        // Le montant ajouté par un renouvellement n'ouvre pas de nouvelle avance.
        return ((float) $c['amount'] - (float) ($c['renewed_amount'] ?? 0)) * self::clamp((float) $c['initial_rate'], 0, 100) / 100;
    }

    public static function totalPaid(array $c, ?string $asOf = null): float
    {
        $sum = 0.0;
        foreach ($c['payments'] as $p) {
            if ($asOf === null || $p['date'] <= $asOf) $sum += (float) $p['amount'];
        }
        return $sum;
    }

    public static function paymentPercent(array $c, ?string $asOf = null): float
    {
        if ((float) $c['amount'] <= 0) return 0.0;
        return (self::initialFinancing($c, $asOf) + self::totalPaid($c, $asOf)) / (float) $c['amount'] * 100;
    }

    public static function latestEntry(array $c): ?array
    {
        $best = null;
        foreach ($c['entries'] as $e) {
            if ($best === null || $e['date'] > $best['date'] || ($e['date'] === $best['date'] && (int) $e['id'] > (int) $best['id'])) $best = $e;
        }
        return $best;
    }

    /** Historique mensuel jusqu'à la date demandée. */
    public static function history(array $c, string $to, ?string $requestedFrom = null): array
    {
        $dates = [];
        foreach ($c['entries'] as $e) $dates[] = $e['date'];
        foreach ($c['payments'] as $p) $dates[] = $p['date'];
        if (!empty($c['guarantee'])) $dates[] = $c['guarantee']['start'];
        $startDate = $c['start_date'] ?: $to;
        $firstData = $dates ? min($dates) : $startDate;
        $from = $requestedFrom ?: min($firstData, $startDate);
        if ($to < $from) $from = $to;

        $points = [];
        $y = (int) substr($from, 0, 4);
        $m = (int) substr($from, 5, 2);
        $lastY = (int) substr($to, 0, 4);
        $lastM = (int) substr($to, 5, 2);
        $guard = 0;
        while (($y < $lastY || ($y === $lastY && $m <= $lastM)) && $guard++ < 600) {
            $cutoff = gmdate('Y-m-d', gmmktime(0, 0, 0, $m + 1, 0, $y));
            if ($cutoff > $to) $cutoff = $to;
            $g = self::guaranteeAt($c['guarantee'] ?? null, $cutoff);
            $points[] = [
                'date' => sprintf('%02d/%04d', $m, $y),
                'progress' => self::pct(self::progress($c, $cutoff)),
                'days' => self::pct(self::elapsed($c, $cutoff)),
                'payment' => self::pct(self::paymentPercent($c, $cutoff)),
                'guarantee' => self::pct($g['consumed']),
            ];
            $m++;
            if ($m > 12) { $m = 1; $y++; }
        }
        if (!$points) $points[] = ['date' => sprintf('%02d/%04d', (int) substr($to, 5, 2), (int) substr($to, 0, 4)), 'progress' => 0, 'days' => 0, 'payment' => 0, 'guarantee' => 0];
        return $points;
    }

    public static function siteEvolution(array $c, string $previous, string $current): array
    {
        if (!empty($c['sites'])) {
            $sites = $c['sites'];
            usort($sites, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
            return array_map(fn ($s) => [
                'name' => trim((string) $s['name']) === '' ? 'Site' : $s['name'],
                'previous' => self::pct(self::siteProgress($s, $previous)),
                'current' => self::pct(self::siteProgress($s, $current)),
            ], $sites);
        }
        return [['name' => 'Contrat', 'previous' => self::pct(self::progress($c, $previous)), 'current' => self::pct(self::progress($c, $current))]];
    }

    /** Évolution aux dates de saisie (page Suivi & avancement). */
    public static function progressEvolution(array $c): array
    {
        $dates = [];
        foreach ($c['entries'] as $e) $dates[$e['date']] = true;
        $dates = array_keys($dates);
        sort($dates);
        if (!$dates) $dates[] = Dates::today();
        $out = [];
        foreach ($dates as $d) {
            $total = max(1, ($c['start_date'] && $c['end_date']) ? Dates::daysBetween($c['start_date'], $c['end_date']) : 1);
            $days = (!$c['start_date'] || $d < $c['start_date']) ? 0 : self::clamp(Dates::daysBetween($c['start_date'], $d) / $total * 100, 0, 100);
            $out[] = ['date' => Dates::fr($d), 'progress' => self::pct(self::progress($c, $d)), 'days' => self::pct($days), 'payment' => self::pct(self::paymentPercent($c, $d)), 'guarantee' => 0];
        }
        return $out;
    }

    // ------------------------------------------------------------------ Garanties
    public static function extCount(?array $g, string $type, ?string $asOf = null): int
    {
        if (!$g) return 0;
        $n = 0;
        foreach ($g['extensions'] as $e) {
            if ($e['type'] !== $type) continue;
            if ($asOf !== null && $e['start'] > $asOf) continue;
            $n++;
        }
        return $n;
    }

    /** Situation de la garantie à une date donnée. */
    public static function guaranteeAt(?array $g, string $asOf): array
    {
        if (!$g) return ['bank' => '—', 'account' => '—', 'rate' => 0.0, 'amount' => 0.0, 'start' => null, 'end' => null, 'consumed' => 0.0, 'status' => 'Non constituée'];
        $ext = $g['extensions'];
        usort($ext, fn ($a, $b) => strcmp($a['start'], $b['start']));
        if (!$ext) {
            $end = $g['end'];
        } else {
            $applicable = [];
            foreach ($ext as $e) if ($e['start'] <= $asOf) $applicable[] = $e['end'];
            $end = $applicable ? max($applicable) : Dates::addDays($ext[0]['start'], -1);
        }
        $start = $g['start'];
        if ($asOf < $start) $consumed = 0.0;
        elseif ($end <= $start) $consumed = $asOf >= $end ? 100.0 : 0.0;
        else $consumed = self::clamp(Dates::daysBetween($start, $asOf) / Dates::daysBetween($start, $end) * 100, 0, 100);
        $status = $asOf < $start ? 'À venir' : ($asOf > $end ? 'Expirée' : ($consumed >= 80 ? 'Alerte >80%' : 'Valide'));
        return ['bank' => (string) $g['bank'], 'account' => (string) $g['account'], 'rate' => (float) $g['rate'], 'amount' => (float) $g['amount'], 'start' => $start, 'end' => $end, 'consumed' => $consumed, 'status' => $status];
    }

    /** Indicateurs « aujourd'hui » : jours consommés, échéance proche, échue, attention… */
    public static function guaranteeFlags(array $g): array
    {
        $start = $g['start'];
        $end = $g['end'];
        $today = Dates::today();
        $total = Dates::daysBetween($start, $end);
        $consumed = $total <= 0 ? ($today >= $end ? 100.0 : 0.0) : self::clamp(Dates::daysBetween($start, $today) / $total * 100, 0, 100);
        $expired = $today > $end;
        $soon = $today >= Dates::addDays($end, -30) && $today <= $end;
        $attention = $expired || $soon || $consumed > 80;
        return [
            'daysConsumedPercent' => self::pct($consumed),
            'isExpired' => $expired,
            'isExpiringSoon' => $soon,
            'daysToExpiry' => Dates::daysBetween($today, $end),
            'needsAttention' => $attention,
            'statusLabel' => $expired ? 'Échue' : ($soon ? 'Échéance proche' : ($consumed > 80 ? 'Vigilance' : 'Active')),
            'statusCss' => $expired ? 'retard' : (($soon || $consumed > 80) ? 'vigilance' : 'normal'),
        ];
    }
}
