<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Indicateurs des rapports périodiques (TDR §11) : un état par contrat à la date de fin de période, et la consommation
 * de carburant de la période (saisies autorisées seulement). Ce sont ces tableaux qui sont figés dans report_snapshots.
 */
class ReportBuilder
{
    /**
     * Colonnes exportables : clé => [libellé, type]. Liste provisoire, à aligner sur la matrice interne du projet ;
     * l'utilisateur choisit les colonnes à l'export.
     */
    public const COLUMNS = [
        'number' => ['N° contrat', 'text'], 'company' => ['Entreprise', 'text'], 'category' => ['Catégorie', 'text'], 'province' => ['Province', 'text'],
        'amount' => ['Coût du contrat (USD)', 'money'], 'progress' => ['Taux d’exécution physique (%)', 'pct'], 'elapsed' => ['Délai consommé (%)', 'pct'],
        'status' => ['Statut', 'text'], 'paid' => ['Montant payé (USD)', 'money'], 'paymentRate' => ['Taux de paiement (%)', 'pct'],
        'remaining' => ['Montant restant (USD)', 'money'], 'decomptesValidated' => ['Décomptes validés (USD)', 'money'],
        'decomptesPending' => ['Décomptes en attente', 'int'], 'observation' => ['Observation', 'text'],
    ];

    /** Bornes d'une période : trimestre (T1..T4), mois (1..12), année, ou dates libres. */
    public static function period(string $type, int $year, int $index = 1, ?string $from = null, ?string $to = null): array
    {
        $months = ['TRIMESTRE' => 3, 'MOIS' => 1, 'ANNEE' => 12];
        if ($type === 'LIBRE') return [$from, $to, 'Du ' . Dates::fr($from) . ' au ' . Dates::fr($to)];
        $len = $months[$type];
        $startMonth = $type === 'ANNEE' ? 1 : ($type === 'TRIMESTRE' ? ($index - 1) * 3 + 1 : $index);
        $start = sprintf('%04d-%02d-01', $year, $startMonth);
        $end = Dates::addDays(Dates::addMonths($start, $len), -1);
        $names = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $label = match ($type) { 'TRIMESTRE' => "T{$index} {$year}", 'MOIS' => ucfirst($names[$index - 1]) . " {$year}", default => "Année {$year}" };
        return [$start, $end, $label];
    }

    /** État des contrats de la phase à la date $asOf. */
    public static function contracts(int $projectId, int $phaseId, string $asOf): array
    {
        $contracts = Loader::contracts($projectId, $phaseId);
        $ids = array_column($contracts, 'id');
        $dec = $ids ? DB::table('contract_decomptes')->whereIn('contract_id', $ids)->where('operation_date', '<=', $asOf)
            ->selectRaw("contract_id, SUM(CASE WHEN status = 'VALIDE' THEN gross_amount ELSE 0 END) AS validated, SUM(CASE WHEN status = 'EN_ATTENTE' THEN 1 ELSE 0 END) AS pending")
            ->groupBy('contract_id')->get()->keyBy('contract_id') : collect();
        $rows = [];
        foreach ($contracts as $c) {
            $paid = Metrics::initialFinancing($c, $asOf) + Metrics::totalPaid($c, $asOf);
            $entry = null;
            // Dernière observation de suivi saisie à la date d'arrêt (saisies triées par date).
            foreach ($c['entries'] as $e) if ($e['date'] <= $asOf && trim((string) $e['observation']) !== '') $entry = $e;
            $rows[] = [
                'id' => $c['id'], 'number' => $c['number'], 'company' => $c['company'], 'category' => $c['category'], 'province' => $c['province'],
                'amount' => (float) $c['amount'], 'progress' => Metrics::pct(Metrics::progress($c, $asOf)), 'elapsed' => Metrics::pct(Metrics::elapsed($c, $asOf)),
                'status' => Metrics::status($c, $asOf), 'paid' => round($paid, 2), 'paymentRate' => Metrics::pct(Metrics::paymentPercent($c, $asOf)),
                'remaining' => round((float) $c['amount'] - $paid, 2),
                'decomptesValidated' => round((float) ($dec[$c['id']]->validated ?? 0), 2), 'decomptesPending' => (int) ($dec[$c['id']]->pending ?? 0),
                'observation' => $entry ? $entry['observation'] : '',
            ];
        }
        return $rows;
    }

    /** Carburant de la période par type d'engin (véhicules, groupes) : seules les saisies autorisées comptent. */
    public static function fuel(int $projectId, string $from, string $to): array
    {
        $assets = DB::table('fleet_assets')->where('project_id', $projectId)->get();
        $logs = DB::table('fleet_logs')->where('project_id', $projectId)->whereBetween('log_date', [$from, $to])->get()->groupBy('fleet_asset_id');
        $out = [];
        foreach ([FleetMetrics::VEHICULE, FleetMetrics::GROUPE] as $kind) {
            $totals = [];
            foreach ($assets->where('kind', $kind) as $a) {
                $asset = ['kind' => $a->kind, 'tank_capacity' => (float) $a->tank_capacity, 'consumption_rate' => $a->consumption_rate === null ? null : (float) $a->consumption_rate, 'fuel_price' => (float) $a->fuel_price];
                $rows = ($logs[$a->id] ?? collect())->map(fn ($l) => [
                    'id' => (int) $l->id, 'date' => Dates::part($l->log_date), 'meter_start' => (float) $l->meter_start, 'meter_end' => (float) $l->meter_end,
                    'fuel_level_before' => null, 'fuel_purchased' => $l->fuel_purchased === null ? null : (float) $l->fuel_purchased,
                    'fuel_price' => $l->fuel_price === null ? null : (float) $l->fuel_price, 'lubricant' => $l->lubricant,
                    'cancelled' => $l->cancelled_at !== null, 'authorized' => $l->authorized_at !== null, 'objected' => $l->objected_at !== null,
                ])->all();
                $totals[] = FleetMetrics::totals($asset, $rows);
            }
            $out[$kind === FleetMetrics::VEHICULE ? 'vehicles' : 'generators'] = FleetMetrics::sum($kind, $totals);
        }
        return $out;
    }

    /** Synthèse d'un état : moyennes et sommes des indicateurs, répartition des statuts. */
    public static function summary(array $rows): array
    {
        $n = count($rows);
        $amount = array_sum(array_column($rows, 'amount'));
        $statuses = [];
        foreach ($rows as $r) $statuses[$r['status']] = ($statuses[$r['status']] ?? 0) + 1;
        return [
            'contracts' => $n, 'amount' => round($amount, 2), 'paid' => round(array_sum(array_column($rows, 'paid')), 2),
            'remaining' => round(array_sum(array_column($rows, 'remaining')), 2),
            'progress' => $n ? round(array_sum(array_column($rows, 'progress')) / $n, 2) : 0.0,
            'paymentRate' => $amount > 0 ? round(array_sum(array_column($rows, 'paid')) / $amount * 100, 2) : 0.0,
            'statuses' => $statuses,
        ];
    }

    /** Écarts entre deux états (A = ancien, B = récent), contrat par contrat. */
    public static function compare(array $a, array $b): array
    {
        $byId = [];
        foreach ($a as $r) $byId[$r['id']] = $r;
        $out = [];
        foreach ($b as $r) {
            $old = $byId[$r['id']] ?? null;
            $out[] = [
                'id' => $r['id'], 'number' => $r['number'], 'company' => $r['company'], 'isNew' => $old === null,
                'progressA' => $old['progress'] ?? null, 'progressB' => $r['progress'], 'progressDelta' => round($r['progress'] - ($old['progress'] ?? 0), 2),
                'paymentRateA' => $old['paymentRate'] ?? null, 'paymentRateB' => $r['paymentRate'], 'paymentRateDelta' => round($r['paymentRate'] - ($old['paymentRate'] ?? 0), 2),
                'paidA' => $old['paid'] ?? null, 'paidB' => $r['paid'], 'paidDelta' => round($r['paid'] - ($old['paid'] ?? 0), 2),
                'statusA' => $old['status'] ?? null, 'statusB' => $r['status'],
            ];
        }
        return $out;
    }
}
