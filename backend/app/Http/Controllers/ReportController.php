<?php
namespace App\Http\Controllers;

use App\Support\ContractSynthesis;
use App\Support\Ctx;
use App\Support\Dates;
use App\Support\ExecutionReport;
use App\Support\GuaranteeAlerts;
use App\Support\Loader;
use App\Support\Metrics;
use App\Support\ReportBuilder;
use App\Support\Views;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // ------------------------------------------------------------------ Périmètre date / période
    private static function born(array $c): string
    {
        $dates = array_filter([$c['signature_date'], $c['start_date']]);
        return $dates ? min($dates) : '0000-00-00';
    }

    private static function endOfLife(array $c, string $asOf): string
    {
        if ($c['terminated'] && $c['termination_date']) return $c['termination_date'];
        if ($c['completed'] || Metrics::progress($c, $asOf) >= 100) {
            $d = array_column($c['entries'], 'date');
            return $d ? max($d) : ($c['end_date'] ?: '9999-12-31');
        }
        return '9999-12-31'; // contrat encore en cours (même après sa date de fin : retard)
    }

    private static function inScope(array $c, string $mode, string $asOf, ?string $from): bool
    {
        if ($mode === 'date') return self::born($c) <= $asOf;
        if ($mode === 'period' && $from) return self::born($c) <= $asOf && self::endOfLife($c, $asOf) >= $from;
        return true;
    }

    /**
     * Synthèse des contrats sur une période (trimestre, mois, année ou dates libres), avec un contrat seul en option.
     * Les indicateurs sont arrêtés à la fin de la période, ou à aujourd'hui si elle n'est pas terminée.
     */
    public function synthesis(Request $r)
    {
        $type = in_array($r->query('type'), ['TRIMESTRE', 'MOIS', 'ANNEE', 'LIBRE'], true) ? $r->query('type') : 'TRIMESTRE';
        $today = Dates::today();
        $year = max(2000, min(2100, (int) $r->query('year', substr($today, 0, 4))));
        $max = $type === 'MOIS' ? 12 : 4;
        $index = max(1, min($max, (int) $r->query('index', 1)));
        $free = fn (string $k) => Dates::valid($r->query($k)) ? Dates::part($r->query($k)) : null;
        if ($type === 'LIBRE') {
            abort_unless($free('from') && $free('to'), 422, 'Choisissez les deux dates de la période.');
            abort_if($free('from') > $free('to'), 422, 'La date de début doit précéder la date de fin.');
        }
        [$from, $to, $label] = ReportBuilder::period($type, $year, $index, $free('from'), $free('to'));
        abort_if($from > $today, 422, 'Cette période n’a pas encore commencé : choisissez une période passée ou en cours.');
        $asOf = min($to, $today);

        $all = Loader::contracts(Ctx::projectId(), Ctx::phaseId());
        $wanted = (int) $r->query('contract_id', 0);
        $rows = [];
        foreach ($all as $c) {
            if (!self::inScope($c, 'period', $asOf, $from) || ($wanted && $c['id'] !== $wanted)) continue;
            $rows[] = ContractSynthesis::row($c, DecompteController::ledger($c), $from, $asOf);
        }
        $rows = ContractSynthesis::sort($rows);
        $summary = ContractSynthesis::summary($rows);
        return [
            'period' => ['type' => $type, 'year' => $year, 'index' => $index, 'from' => $from, 'to' => $to, 'asOf' => $asOf, 'label' => $label, 'isOpen' => $to > $today],
            'contracts' => array_map(fn ($c) => ['id' => $c['id'], 'number' => $c['number'], 'company' => $c['company']], $all),
            'rows' => $rows, 'summary' => $summary, 'lead' => ContractSynthesis::lead($rows, $summary, $asOf), 'findings' => array_map(fn ($f) => ['level' => $f[0], 'text' => $f[1]], ContractSynthesis::findings($rows, $summary)),
            'issuedBy' => ['name' => Ctx::user()->full_name, 'title' => Ctx::title(Ctx::user()), 'date' => $today],
        ];
    }

    // ------------------------------------------------------------------ État d'exécution (matrice du projet)

    /** Date d'arrêt de la matrice : la date demandée, jamais dans le futur. */
    private static function executionDate(Request $r): string
    {
        $today = Dates::today();
        $d = Dates::valid($r->query('date')) ? Dates::part($r->query('date')) : $today;
        return min($d, $today);
    }

    /** « 31 août 2026 », comme dans le titre de la matrice. */
    private static function longDate(string $ymd): string
    {
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        return (int) substr($ymd, 8, 2) . ' ' . $months[(int) substr($ymd, 5, 2) - 1] . ' ' . substr($ymd, 0, 4);
    }

    private function executionData(string $asOf): array
    {
        // Contrats déjà signés ou démarrés à la date d'arrêt, de la phase consultée (sans les fournitures de bureau).
        $contracts = array_values(array_filter(Loader::contracts(Ctx::projectId(), Ctx::phaseId()), fn ($c) => self::inScope($c, 'date', $asOf, null)));
        return ExecutionReport::build($contracts, $asOf) + [
            'project' => GuaranteeAlerts::projectLabel(Ctx::projectId()), 'longDate' => self::longDate($asOf),
            'issuedBy' => ['name' => Ctx::user()->full_name, 'title' => Ctx::title(Ctx::user()), 'date' => Dates::today()],
        ];
    }

    /** État d'exécution des travaux, activités et prestations à une date (décision du 10/11/2026). */
    public function execution(Request $r)
    {
        return $this->executionData(self::executionDate($r));
    }

    /**
     * La même matrice en Excel, colonne pour colonne comme le modèle du projet : une ligne par contrat, suivie d'une ligne
     * par site, sous les bandeaux de la composante, de sa catégorie et de la province ; « Composantes » et « Budget » (activité
     * de la composante et son budget en UC) fusionnées sur les contrats de l'activité, les colonnes du contrat sur ses sites.
     */
    public function executionExport(Request $r)
    {
        $asOf = self::executionDate($r);
        $d = $this->executionData($asOf);
        [$H, $B, $M, $P, $T, $TITLE] = [1, 2, 3, 4, 6, 7];
        $cols = ['Composantes', 'Budget en millions d’UC', 'Activités', 'Fournisseurs', 'Coût en USD', 'Conso. du délai contractuel', 'Réalisation physique au ' . Dates::fr($asOf),
            'Performance env. et sociale', 'Taux de réalisation physique', 'Engagement', 'Paiement', 'Taux de paiement', 'Observations'];
        $last = Xlsx::col(count($cols) - 1);
        $rows = [[['v' => "ÉTAT D'EXÉCUTION DES TRAVAUX, ACTIVITÉS ET PRESTATIONS DU {$d['project']} AU " . mb_strtoupper($d['longDate']), 's' => $TITLE]], [], array_map(fn ($c) => ['v' => $c, 's' => $H], $cols)];
        $merges = ["A1:{$last}1"];
        $band = function (string $text) use (&$rows, &$merges, $B, $last) {
            $rows[] = [['v' => $text, 's' => $B]];
            $merges[] = 'A' . count($rows) . ":{$last}" . count($rows);
        };
        $vmerge = function (string $col, int $from, int $lines) use (&$merges) {
            if ($lines > 1) $merges[] = "{$col}{$from}:{$col}" . ($from + $lines - 1);
        };
        $blank = ['v' => '', 's' => $T];
        $done = fn (array $list) => $list ? ' — Ouvrages terminés : ' . implode(' ; ', array_map(fn ($x) => "{$x['type']} : {$x['count']}", $list)) : '';
        // Coût en UC (composante, catégorie) puis chiffres des contrats, comme les bandeaux de la matrice du projet.
        $cost = fn ($uc) => $uc === null ? '' : ' ; Coût : ' . number_format((float) $uc, 0, ',', ' ') . ' UC';
        $summary = fn (array $t) => " — {$t['contracts']} contrat(s), coût " . number_format($t['amount'], 2, ',', ' ') . ' USD (' . number_format($t['progress'], 1, ',', ' ') . ' % de réalisation physique)';
        $several = count($d['sections']) > 1;
        foreach ($d['sections'] as $section) {
            if ($section['id'] !== null) $band(mb_strtoupper($section['name']) . $cost($section['total']) . $summary($section['totals']));
            elseif ($several) $band('CONTRATS NON RATTACHÉS À UNE COMPOSANTE' . $summary($section['totals']));
            foreach ($section['categories'] as $cat) {
                $band($cat['label'] . $cost($cat['cost']) . $summary($cat['totals']));
                foreach ($cat['provinces'] as $p) {
                    $band("Province {$p['province']}" . $done($p['completed']));
                    $byId = [];
                    foreach ($p['rows'] as $x) $byId[$x['id']] = $x;
                    foreach ($p['components'] as $g) {
                        $groupStart = count($rows) + 1;
                        foreach ($g['contractIds'] as $k => $id) {
                            $x = $byId[$id];
                            $start = count($rows) + 1;
                            $lines = 1 + count($x['sites']);
                            $supplier = $x['company'] . ($x['signatureDate'] ? "\nContrat signé le " . Dates::fr($x['signatureDate']) : '') . ($x['startDate'] ? "\nDémarrage le " . Dates::fr($x['startDate']) : '') . "\nDélai : {$x['durationMonths']} mois";
                            $realization = trim(($x['installation'] ? 'Installation de chantier et travaux préparatoires : ' . number_format($x['installation']['progress'], 1, ',', ' ') . " %\n" : '') . $x['realization']);
                            $rows[] = [$k === 0 ? ['v' => $g['name'], 's' => $T] : $blank, $k === 0 ? ['v' => $g['budget'] ?? '', 's' => $g['budget'] === null ? $T : $M] : $blank,
                                ['v' => "Contrat N° {$x['number']} : " . ($x['activity'] ?: $x['title']), 's' => $T], ['v' => $supplier, 's' => $T], ['v' => $x['amount'], 's' => $M], ['v' => $x['elapsed'], 's' => $P],
                                ['v' => $realization, 's' => $T], ['v' => $x['esPerformance'], 's' => $T], ['v' => $x['progress'], 's' => $P], ['v' => $x['engagement'], 's' => $M], ['v' => $x['paid'], 's' => $M],
                                ['v' => $x['paymentRate'], 's' => $P], ['v' => $x['observation'], 's' => $T]];
                            foreach ($x['sites'] as $s) {
                                $what = trim(implode(' — ', array_filter([$s['workType'], $s['description'] !== $s['workType'] ? $s['description'] : ''])));
                                $rows[] = [$blank, $blank, $blank, $blank, $blank, $blank,
                                    ['v' => "Site {$s['name']}" . ($what ? " : {$what}" : '') . ($s['situation'] ? "\nSituation actuelle : {$s['situation']}" : ''), 's' => $T],
                                    $blank, ['v' => $s['progress'], 's' => $P], $blank, $blank, $blank, ['v' => $s['observation'], 's' => $T]];
                            }
                            foreach (['C', 'D', 'E', 'F', 'H', 'J', 'K', 'L'] as $col) $vmerge($col, $start, $lines);
                        }
                        $vmerge('A', $groupStart, $g['lines']);
                        $vmerge('B', $groupStart, $g['lines']);
                    }
                }
            }
        }
        $t = $d['totals'];
        $rows[] = [['v' => "Total — {$t['contracts']} contrat(s)", 's' => $B], $blank, $blank, $blank, ['v' => $t['amount'], 's' => $M], $blank, $blank, $blank,
            ['v' => $t['progress'], 's' => $P], ['v' => $t['amount'], 's' => $M], ['v' => $t['paid'], 's' => $M], ['v' => $t['paymentRate'], 's' => $P], $blank];
        $bin = Xlsx::build([['name' => 'État d’exécution', 'rows' => $rows, 'merges' => $merges, 'widths' => [26, 13, 36, 22, 15, 11, 48, 12, 12, 15, 15, 11, 42], 'freezeRows' => 3]]);
        $code = (string) DB::table('projects')->where('id', Ctx::projectId())->value('code');
        Ctx::audit('EXPORT_RAPPORT', 'Report', $code, 'Export Excel de l’état d’exécution au ' . Dates::fr($asOf) . '.');
        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="etat_execution_' . $code . '_' . $asOf . '.xlsx"',
        ]);
    }

    private function params(Request $r): array
    {
        $mode = in_array($r->query('mode'), ['date', 'period'], true) ? $r->query('mode') : 'current';
        $date = Dates::valid($r->query('date')) ? Dates::part($r->query('date')) : null;
        $from = Dates::valid($r->query('from')) ? Dates::part($r->query('from')) : null;
        $to = Dates::valid($r->query('to')) ? Dates::part($r->query('to')) : null;
        $asOf = $mode === 'date' && $date ? $date : ($mode === 'period' && $to ? $to : Dates::today());
        $periodFrom = $mode === 'period' ? $from : null;
        if ($periodFrom && $periodFrom > $asOf) abort(422, 'La date de début de période doit être antérieure à la date de fin.');
        return [$mode, $asOf, $periodFrom, filter_var($r->query('show_all'), FILTER_VALIDATE_BOOLEAN)];
    }

    private function snapshot(array $c, string $asOf, ?string $from): array
    {
        $g = Metrics::guaranteeAt($c['guarantee'], $asOf);
        $initial = Metrics::initialFinancing($c, $asOf);
        $paid = Metrics::totalPaid($c, $asOf);
        $latest = null;
        foreach ($c['entries'] as $e) if ($e['date'] <= $asOf && ($latest === null || $e['date'] >= $latest['date'])) $latest = $e;
        $inPeriod = fn ($d) => $d <= $asOf && (!$from || $d >= $from);
        return [
            'contractId' => $c['id'], 'category' => $c['category'], 'number' => $c['number'], 'company' => $c['company'],
            'province' => implode(', ', array_values(array_unique(array_filter(array_column($c['sites'], 'province'))))) ?: $c['province'],
            'sites' => implode(', ', array_column($c['sites'], 'name')), 'activity' => $c['activity'], 'signatureDate' => $c['signature_date'],
            'startDate' => $c['start_date'], 'endDate' => $c['end_date'], 'durationMonths' => $c['duration_months'], 'amount' => $c['amount'],
            'progress' => Metrics::pct(Metrics::progress($c, $asOf)), 'days' => Metrics::pct(Metrics::elapsed($c, $asOf)), 'payment' => Metrics::pct(Metrics::paymentPercent($c, $asOf)),
            'status' => Metrics::status($c, $asOf), 'initialRate' => $c['initial_rate'], 'initialFinancing' => $initial, 'totalPaid' => $paid, 'paidIncludingInitial' => $initial + $paid,
            'latestRealization' => $latest ? $latest['realization'] : '', 'latestObservation' => $latest ? $latest['observation'] : '',
            'amendments' => count(array_filter($c['amendments'], fn ($a) => $a['date'] <= $asOf)), 'amendmentsInPeriod' => count(array_filter($c['amendments'], fn ($a) => $inPeriod($a['date']))),
            'entries' => count(array_filter($c['entries'], fn ($e) => $e['date'] <= $asOf)), 'paymentsInPeriod' => count(array_filter($c['payments'], fn ($p) => $inPeriod($p['date']))),
            'terminationDate' => $c['termination_date'], 'terminationReason' => $c['termination_reason'], 'guaranteeStatus' => $g['status'],
        ];
    }

    private function matrix(array $contracts, string $asOf, ?string $from): array
    {
        $first = $from ?: Dates::addMonths($asOf, -5);
        $y = (int) substr($first, 0, 4);
        $m = (int) substr($first, 5, 2);
        $lastY = (int) substr($asOf, 0, 4);
        $lastM = (int) substr($asOf, 5, 2);
        $cutoffs = [];
        $guard = 0;
        while (($y < $lastY || ($y === $lastY && $m <= $lastM)) && $guard++ < 600) {
            $cut = gmdate('Y-m-d', gmmktime(0, 0, 0, $m + 1, 0, $y));
            $cutoffs[] = $cut > $asOf ? $asOf : $cut;
            $m++;
            if ($m > 12) { $m = 1; $y++; }
        }
        if (count($cutoffs) > 12) $cutoffs = array_slice($cutoffs, -12);
        if (!$cutoffs) $cutoffs[] = $asOf;
        $months = array_map(fn ($d) => substr($d, 5, 2) . '/' . substr($d, 0, 4), $cutoffs);

        $rows = [];
        foreach ($contracts as $c) {
            $g = $c['guarantee'];
            $state = Metrics::guaranteeAt($g, $asOf);
            $rows[] = [
                'contractId' => $c['id'], 'number' => $c['number'], 'company' => $c['company'], 'bank' => $state['bank'], 'account' => $state['account'], 'rate' => $state['rate'],
                'amount' => $state['amount'], 'start' => $state['start'], 'end' => $state['end'], 'consumed' => Metrics::pct($state['consumed']), 'status' => $state['status'],
                'extensions' => Metrics::extCount($g, 'PROLONGATION', $asOf), 'renewals' => Metrics::extCount($g, 'RENOUVELLEMENT', $asOf),
                'daysLeft' => $state['end'] ? Dates::daysBetween($asOf, $state['end']) : null,
                'timeline' => array_map(fn ($d) => Metrics::guaranteeAt($g, $d)['status'], $cutoffs),
            ];
        }
        $rank = ['Expirée' => 0, 'Alerte >80%' => 1, 'Valide' => 2];
        usort($rows, fn ($a, $b) => (($rank[$a['status']] ?? 3) <=> ($rank[$b['status']] ?? 3)) ?: strcmp((string) $a['end'], (string) $b['end']) ?: strcmp($a['number'], $b['number']));
        return [$rows, $months];
    }

    public function index(Request $r)
    {
        [$mode, $asOf, $from, $showAll] = $this->params($r);
        $all = Loader::contracts(Ctx::projectId(), Ctx::phaseId());
        $contracts = $showAll ? $all : array_values(array_filter($all, fn ($c) => self::inScope($c, $mode, $asOf, $from)));
        $rows = array_map(fn ($c) => $this->snapshot($c, $asOf, $from), $contracts);
        [$matrix, $months] = $this->matrix($contracts, $asOf, $from);

        $sel = null;
        $wanted = (int) $r->query('contract_id', 0);
        foreach ($contracts as $c) if ($c['id'] === $wanted) $sel = $c;
        $sel = $sel ?: ($contracts[0] ?? null);
        $prev = $from ?: Dates::addMonths($asOf, -1);
        if ($prev >= $asOf) $prev = Dates::addMonths($asOf, -1);
        $count = fn ($s) => count(array_filter($rows, fn ($x) => $x['status'] === $s));
        $mc = fn ($s) => count(array_filter($matrix, fn ($x) => $x['status'] === $s));

        return [
            'mode' => $mode, 'asOf' => $asOf, 'from' => $from, 'showAll' => $showAll, 'totalContracts' => count($all), 'inScope' => count($contracts),
            'rows' => $rows,
            'kpis' => ['count' => count($rows), 'average' => $rows ? Metrics::pct(array_sum(array_column($rows, 'progress')) / count($rows)) : 0,
                'normal' => $count('Normal'), 'vigilance' => $count('Vigilance'), 'retard' => $count('Retard'), 'termine' => $count('Terminé'), 'resilie' => $count('Résilié')],
            'contracts' => array_map(fn ($c) => ['id' => $c['id'], 'number' => $c['number']], $contracts),
            'selected' => $sel ? $sel['id'] : null,
            'history' => $sel ? Metrics::history($sel, $asOf, $from) : [],
            'siteEvolution' => $sel ? Metrics::siteEvolution($sel, $prev, $asOf) : [], 'sitePrevLabel' => Dates::fr($prev), 'siteCurLabel' => Dates::fr($asOf),
            'matrix' => ['rows' => $matrix, 'months' => $months, 'valid' => $mc('Valide'), 'warn' => $mc('Alerte >80%'), 'expired' => $mc('Expirée'), 'none' => $mc('Non constituée'),
                'amount' => array_sum(array_map(fn ($x) => in_array($x['status'], ['Non constituée', 'À venir'], true) ? 0 : $x['amount'], $matrix))],
        ];
    }

    public function export(Request $r)
    {
        [$mode, $asOf, $from, $showAll] = $this->params($r);
        $all = Loader::contracts(Ctx::projectId(), Ctx::phaseId());
        $contracts = $showAll ? $all : array_values(array_filter($all, fn ($c) => self::inScope($c, $mode, $asOf, $from)));
        $rows = array_map(fn ($c) => $this->snapshot($c, $asOf, $from), $contracts);
        [$matrix, $months] = $this->matrix($contracts, $asOf, $from);
        $project = Ctx::project();
        $H = Xlsx::HEADER; $M = Xlsx::MONEY; $P = Xlsx::PCT; $D = Xlsx::DATE; $T = Xlsx::TEXT;
        $title = "Rapport de suivi — {$project->code} — " . ($from ? 'période du ' . Dates::fr($from) . ' au ' . Dates::fr($asOf) : 'situation au ' . Dates::fr($asOf));

        $head1 = ['Catégorie', 'N° contrat', 'Entreprise', 'Province(s)', 'Site(s)', 'Activité', 'Signature', 'Démarrage', 'Fin', 'Durée (mois)', 'Montant', 'Avancement', 'Jours consommés', 'Taux paiement', 'Statut',
            'Avance de démarrage', 'Total payé', 'Dernière réalisation', 'Dernière observation', 'Avenants', 'Situations', 'Date résiliation', 'Motif résiliation'];
        $s1 = [[['v' => $title, 's' => Xlsx::TITLE]], [], array_map(fn ($h) => ['v' => $h, 's' => $H], $head1)];
        foreach ($rows as $x) {
            $s1[] = [['v' => $x['category'], 's' => $T], ['v' => $x['number'], 's' => $T], ['v' => $x['company'], 's' => $T], ['v' => $x['province'], 's' => $T], ['v' => $x['sites'], 's' => $T], ['v' => $x['activity'], 's' => $T],
                ['d' => $x['signatureDate'] ?: '', 's' => $D], ['d' => $x['startDate'] ?: '', 's' => $D], ['d' => $x['endDate'] ?: '', 's' => $D], ['v' => (int) $x['durationMonths'], 's' => $T], ['v' => (float) $x['amount'], 's' => $M],
                ['v' => (float) $x['progress'], 's' => $P], ['v' => (float) $x['days'], 's' => $P], ['v' => (float) $x['payment'], 's' => $P], ['v' => $x['status'], 's' => $T],
                ['v' => (float) $x['initialFinancing'], 's' => $M], ['v' => (float) $x['totalPaid'], 's' => $M], ['v' => $x['latestRealization'], 's' => $T], ['v' => $x['latestObservation'], 's' => $T],
                ['v' => (int) $x['amendments'], 's' => $T], ['v' => (int) $x['entries'], 's' => $T], ['d' => $x['terminationDate'] ?: '', 's' => $D], ['v' => $x['terminationReason'], 's' => $T]];
        }
        $head2 = array_merge(['N° contrat', 'Entreprise', 'Banque', 'N° garantie', 'Taux', 'Montant', 'Début', 'Échéance', 'Consommation', 'Prolongations', 'Renouvellements', 'Statut'], $months);
        $s2 = [[['v' => 'Matrice des garanties de bonne exécution — ' . Dates::fr($asOf), 's' => Xlsx::TITLE]], [], array_map(fn ($h) => ['v' => $h, 's' => $H], $head2)];
        foreach ($matrix as $x) {
            $s2[] = array_merge([['v' => $x['number'], 's' => $T], ['v' => $x['company'], 's' => $T], ['v' => $x['bank'], 's' => $T], ['v' => $x['account'], 's' => $T], ['v' => (float) $x['rate'], 's' => $P],
                ['v' => (float) $x['amount'], 's' => $M], ['d' => $x['start'] ?: '', 's' => $D], ['d' => $x['end'] ?: '', 's' => $D], ['v' => (float) $x['consumed'], 's' => $P],
                ['v' => (int) $x['extensions'], 's' => $T], ['v' => (int) $x['renewals'], 's' => $T], ['v' => $x['status'], 's' => $T]], array_map(fn ($st) => ['v' => $st, 's' => $T], $x['timeline']));
        }
        $bin = Xlsx::build([
            ['name' => 'Rapport de suivi', 'rows' => $s1, 'widths' => [14, 18, 26, 22, 28, 30, 12, 12, 12, 10, 16, 12, 14, 12, 12, 16, 16, 30, 30, 10, 10, 13, 30], 'freezeRows' => 3],
            ['name' => 'Garanties', 'rows' => $s2, 'widths' => array_merge([18, 26, 22, 20, 8, 16, 12, 12, 13, 12, 13, 14], array_fill(0, count($months), 11)), 'freezeRows' => 3],
        ]);
        Ctx::audit('EXPORT_RAPPORT', 'Report', $project->code, 'Export Excel du rapport au ' . Dates::fr($asOf) . '.');
        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="rapport_' . $project->code . '_' . $asOf . '.xlsx"',
        ]);
    }
}
