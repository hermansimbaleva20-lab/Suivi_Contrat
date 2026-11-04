<?php

namespace App\Support;

/**
 * Synthèse des contrats sur une période (Rapports & suivi) : indicateurs de performance par contrat, arrêtés à la fin de la
 * période, flux de la période (avancement gagné, paiements), situation d'ensemble et constats rédigés à partir des chiffres.
 * Les statuts restent ceux de Metrics : le portefeuille les compte, il ne les moyenne jamais. Fonctions pures (testées) :
 * elles travaillent sur les tableaux de Loader et sur les lignes de DecompteLedger.
 */
class ContractSynthesis
{
    /** Ordre de lecture : les contrats qui demandent une action d'abord. */
    private const STATUS_ORDER = ['Retard' => 0, 'Vigilance' => 1, 'Normal' => 2, 'Terminé' => 3, 'Résilié' => 4];

    /** Indicateurs d'un contrat au jour $asOf ; $from borne les flux de la période. $ledger : lignes de DecompteLedger::rows. */
    public static function row(array $c, array $ledger, string $from, string $asOf): array
    {
        $progress = Metrics::pct(Metrics::progress($c, $asOf));
        $before = Metrics::pct(Metrics::progress($c, Dates::addDays($from, -1)));
        $elapsed = Metrics::pct(Metrics::elapsed($c, $asOf));
        $status = Metrics::status($c, $asOf);
        $advance = Metrics::initialFinancing($c, $asOf);
        $paid = $advance + Metrics::totalPaid($c, $asOf);
        $paymentRate = Metrics::pct(Metrics::paymentPercent($c, $asOf));
        $paidInPeriod = 0.0;
        $paidDecomptes = [];
        foreach ($c['payments'] as $p) {
            if ($p['date'] > $asOf) continue;
            if ($p['date'] >= $from) $paidInPeriod += (float) $p['amount'];
            if (!empty($p['decompteId'])) $paidDecomptes[$p['decompteId']] = true;
        }
        $validated = 0.0;
        $toPay = 0.0;
        $pending = 0;
        foreach ($ledger as $l) {
            if ($l['status'] === DecompteLedger::PENDING && $l['date'] <= $asOf) $pending++;
            if ($l['status'] !== DecompteLedger::VALID || ($l['decisionDate'] ?? $l['date']) > $asOf) continue;
            $validated += (float) $l['gross_amount'];
            if (empty($paidDecomptes[$l['id']])) $toPay += (float) $l['net'];
        }
        $g = Metrics::guaranteeAt($c['guarantee'] ?? null, $asOf);
        $isService = strtoupper((string) $c['category']) === 'SERVICE' || !empty($c['renewal_no']); // sans garantie exigée
        $sitesDone = count(array_filter($c['sites'], fn ($s) => Metrics::siteProgress($s, $asOf) >= 100));
        $observation = '';
        $entriesInPeriod = 0;
        foreach ($c['entries'] as $e) {
            if ($e['date'] > $asOf) continue;
            if ($e['date'] >= $from) $entriesInPeriod++;
            if (trim((string) $e['observation']) !== '') $observation = trim((string) $e['observation']);
        }
        return [
            'id' => $c['id'], 'number' => $c['number'], 'title' => $c['title'], 'company' => $c['company'], 'category' => $c['category'], 'province' => $c['province'],
            'amount' => (float) $c['amount'], 'startDate' => $c['start_date'], 'endDate' => $c['end_date'], 'status' => $status,
            'amendments' => count(array_filter($c['amendments'], fn ($a) => $a['date'] <= $asOf)), 'renewals' => count(array_filter($c['renewals'] ?? [], fn ($x) => $x['date'] <= $asOf)),
            'progress' => $progress, 'progressGain' => round($progress - $before, 2), 'elapsed' => $elapsed, 'gap' => round($progress - $elapsed, 2),
            // Indice de performance des délais : avancement physique rapporté au délai consommé (1 = au rythme prévu).
            'spi' => $elapsed > 0 ? round($progress / $elapsed, 2) : null,
            'daysLate' => $status === 'Retard' && $c['end_date'] && $asOf > $c['end_date'] ? Dates::daysBetween($c['end_date'], $asOf) : 0,
            'paid' => round($paid, 2), 'paidInPeriod' => round($paidInPeriod, 2), 'paymentRate' => $paymentRate, 'paymentGap' => round($paymentRate - $progress, 2),
            'remaining' => round((float) $c['amount'] - $paid, 2), 'overpaid' => $paymentRate > $progress,
            'decomptesValidated' => round($validated, 2), 'decomptesPending' => $pending, 'toPay' => round($toPay, 2),
            'guarantee' => $isService ? 'Sans objet' : $g['status'], 'guaranteeEnd' => $isService ? null : $g['end'], 'guaranteeNumber' => $c['guarantee']['account'] ?? '',
            'guaranteeConsumed' => $isService ? null : Metrics::pct($g['consumed']),
            'sitesTotal' => count($c['sites']), 'sitesDone' => $sitesDone, 'entriesInPeriod' => $entriesInPeriod, 'observation' => $observation,
        ];
    }

    /** Lignes à lire en premier : retards, puis vigilance…, et les plus grands écarts en tête de chaque statut. */
    public static function sort(array $rows): array
    {
        usort($rows, fn ($a, $b) => [self::STATUS_ORDER[$a['status']] ?? 9, $a['gap'], $a['number']] <=> [self::STATUS_ORDER[$b['status']] ?? 9, $b['gap'], $b['number']]);
        return $rows;
    }

    public static function summary(array $rows): array
    {
        $n = count($rows);
        $sum = fn (string $k) => round(array_sum(array_column($rows, $k)), 2);
        $amount = $sum('amount');
        $statuses = array_fill_keys(array_keys(self::STATUS_ORDER), 0);
        $guarantees = [];
        $categories = [];
        foreach ($rows as $r) {
            $statuses[$r['status']] = ($statuses[$r['status']] ?? 0) + 1;
            $guarantees[$r['guarantee']] = ($guarantees[$r['guarantee']] ?? 0) + 1;
            $cat = $r['category'] ?: 'Non classé';
            $categories[$cat] ??= ['category' => $cat, 'contracts' => 0, 'amount' => 0.0, 'paid' => 0.0, 'progressSum' => 0.0, 'elapsedSum' => 0.0];
            $categories[$cat]['contracts']++;
            $categories[$cat]['amount'] += $r['amount'];
            $categories[$cat]['paid'] += $r['paid'];
            $categories[$cat]['progressSum'] += $r['progress'];
            $categories[$cat]['elapsedSum'] += $r['elapsed'];
        }
        $byCategory = array_map(fn ($x) => [
            'category' => $x['category'], 'contracts' => $x['contracts'], 'amount' => round($x['amount'], 2), 'progress' => round($x['progressSum'] / $x['contracts'], 1),
            'elapsed' => round($x['elapsedSum'] / $x['contracts'], 1), 'paymentRate' => $x['amount'] > 0 ? round($x['paid'] / $x['amount'] * 100, 1) : 0.0,
        ], array_values($categories));
        return [
            'contracts' => $n, 'amount' => $amount, 'paid' => $sum('paid'), 'paidInPeriod' => $sum('paidInPeriod'), 'remaining' => $sum('remaining'),
            'progress' => $n ? round(array_sum(array_column($rows, 'progress')) / $n, 1) : 0.0,
            'progressGain' => $n ? round(array_sum(array_column($rows, 'progressGain')) / $n, 1) : 0.0,
            'elapsed' => $n ? round(array_sum(array_column($rows, 'elapsed')) / $n, 1) : 0.0,
            'paymentRate' => $amount > 0 ? round($sum('paid') / $amount * 100, 1) : 0.0,
            'decomptesValidated' => $sum('decomptesValidated'), 'decomptesPending' => (int) $sum('decomptesPending'), 'toPay' => $sum('toPay'),
            'statuses' => $statuses, 'guarantees' => $guarantees, 'categories' => $byCategory,
        ];
    }

    /** « 068, 069 et 2 autres » : assez pour agir sans noyer le lecteur. */
    private static function names(array $rows, int $max = 5): string
    {
        $names = array_column($rows, 'number');
        if (count($names) <= $max) return implode(', ', $names);
        return implode(', ', array_slice($names, 0, $max)) . ' et ' . (count($names) - $max) . ' autre(s)';
    }

    private static function usd(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' USD';
    }

    private static function pts(float $v): string
    {
        return number_format($v, 1, ',', ' ');
    }

    /** Résumé en une phrase ou deux, tiré des chiffres. */
    public static function lead(array $rows, array $s, string $asOf): string
    {
        if (!$rows) return 'Aucun contrat n’était actif sur cette période.';
        $count = $s['contracts'] > 1 ? "{$s['contracts']} contrats" : '1 contrat';
        $text = 'Au ' . Dates::fr($asOf) . ", le portefeuille compte {$count} pour " . self::usd($s['amount']) . '. L’avancement physique moyen atteint ' . self::pts($s['progress'])
            . ' % pour ' . self::pts($s['elapsed']) . ' % de délai consommé, et ' . self::pts($s['paymentRate']) . ' % du montant contractuel est payé.';
        $say = fn (int $n, string $state) => $n > 1 ? "{$n} contrats sont {$state}" : "1 contrat est {$state}";
        $parts = [];
        if ($s['statuses']['Retard'] ?? 0) $parts[] = $say($s['statuses']['Retard'], 'en retard');
        if ($s['statuses']['Vigilance'] ?? 0) $parts[] = $say($s['statuses']['Vigilance'], 'en vigilance');
        if ($parts) $text .= ' ' . implode(' et ', $parts) . '.';
        return $text;
    }

    /** Constats [level, text], du plus urgent au plus favorable. level : critical | warning | info | good. */
    public static function findings(array $rows, array $s): array
    {
        if (!$rows) return [];
        $where = fn (callable $f) => array_values(array_filter($rows, $f));
        $out = [];
        $many = fn (array $list, string $one, string $several) => count($list) > 1 ? count($list) . " {$several}" : "1 {$one}";
        $late = $where(fn ($r) => $r['status'] === 'Retard');
        if ($late) $out[] = ['critical', $many($late, 'contrat en retard', 'contrats en retard') . ' : ' . self::names($late) . '.'];
        $expired = $where(fn ($r) => $r['guarantee'] === 'Expirée');
        if ($expired) $out[] = ['critical', $many($expired, 'garantie de bonne exécution expirée', 'garanties de bonne exécution expirées') . ' : ' . self::names($expired) . '.'];
        $watch = $where(fn ($r) => $r['status'] === 'Vigilance');
        if ($watch) $out[] = ['warning', $many($watch, 'contrat en vigilance', 'contrats en vigilance') . ' : ' . self::names($watch) . '.'];
        $over = $where(fn ($r) => $r['overpaid'] && !in_array($r['status'], ['Terminé', 'Résilié'], true));
        if ($over) $out[] = ['warning', 'Taux de paiement supérieur à l’avancement physique : ' . self::names($over) . '.'];
        $soon = $where(fn ($r) => $r['guarantee'] === 'Alerte >80%');
        if ($soon) $out[] = ['warning', $many($soon, 'garantie a consommé', 'garanties ont consommé') . ' plus de 80 % de la durée : ' . self::names($soon) . '.'];
        $silent = $where(fn ($r) => $r['entriesInPeriod'] === 0 && !in_array($r['status'], ['Terminé', 'Résilié'], true));
        if ($silent) $out[] = ['warning', 'Aucun avancement saisi sur la période : ' . self::names($silent) . '.'];
        if ($s['decomptesPending'] > 0) $out[] = ['info', $s['decomptesPending'] > 1 ? "{$s['decomptesPending']} décomptes en attente de validation." : '1 décompte en attente de validation.'];
        if ($s['toPay'] > 0) $out[] = ['info', 'Décomptes validés restant à payer : ' . self::usd($s['toPay']) . '.'];
        if ($s['progressGain'] > 0) $out[] = ['good', 'Avancement physique moyen en hausse de ' . self::pts($s['progressGain']) . ' points sur la période.'];
        $done = $where(fn ($r) => $r['status'] === 'Terminé');
        if ($done) $out[] = ['good', $many($done, 'contrat terminé', 'contrats terminés') . ' : ' . self::names($done) . '.'];
        return $out;
    }
}
