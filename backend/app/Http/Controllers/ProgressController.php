<?php
namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use App\Support\GoodsProcedure;
use App\Support\Loader;
use App\Support\Metrics;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgressController extends Controller
{
    /** Composante « installation de chantier et travaux préparatoires » : une situation sans site (site_id = INSTALLATION). */
    private const INSTALLATION = 'Installation de chantier et travaux préparatoires';

    /** Performance environnementale et sociale, colonne de la matrice d'exécution du projet. */
    public const ES_LEVELS = ['Bon', 'Moyen', 'Mauvais'];

    private static function remaining(float $done): string
    {
        return rtrim(rtrim(number_format(max(0, 100 - $done), 2, ',', ''), '0'), ',');
    }

    private function contractOr404(int $id): array
    {
        $c = Loader::contract(Ctx::projectId(), Ctx::phaseId(), $id, true);
        abort_unless($c, 404, 'Contrat introuvable.');
        return $c;
    }

    public function index(Request $r)
    {
        $all = Loader::contracts(Ctx::projectId(), Ctx::phaseId());
        $list = array_map(fn ($c) => ['id' => $c['id'], 'number' => $c['number'], 'company' => $c['company'], 'isTerminated' => $c['terminated']], $all);
        if (!$all) return ['contracts' => [], 'selected' => null];
        $sel = null;
        $wanted = (int) $r->query('contract_id', 0);
        foreach ($all as $c) if ($c['id'] === $wanted) $sel = $c;
        $sel = $sel ?: $all[0];

        $sites = [];
        foreach ($sel['sites'] as $s) $sites[$s['id']] = $s;
        // Cumul par site (et pour l'installation de chantier) à chaque saisie, dans l'ordre chronologique.
        $cum = [];
        $running = [];
        foreach ($sel['entries'] as $e) {
            $k = $e['installation'] ? 'I' : ($e['site_id'] ?: 0);
            $running[$k] = ($running[$k] ?? 0) + $e['progress'];
            $cum[$e['id']] = min(100, $running[$k]);
        }
        $entries = array_reverse($sel['entries']);
        $size = 7;
        [$page, $pages, $offset] = Views::paginate(count($entries), (int) $r->query('page', 1), $size);
        $today = Dates::today();
        $prev = Dates::addMonths($today, -1);
        // Poids et contribution de chaque composante (montant ÷ base) : affichés pendant la saisie.
        $breakdown = Views::progressBreakdown($sel);
        $bySite = [];
        $installation = null;
        foreach ($breakdown['rows'] as $row) {
            if ($row['kind'] === 'INSTALLATION') $installation = $row + ['remaining' => Metrics::pct(max(0, 100 - $row['progress']))];
            else $bySite[$row['siteId']] = $row;
        }
        return [
            'contracts' => $list,
            'selected' => Views::contract($sel, true),
            'sites' => array_map(fn ($s) => Views::site($s) + ['remaining' => Metrics::pct(max(0, 100 - Metrics::siteProgress($s))),
                'weight' => $bySite[$s['id']]['weight'] ?? null, 'contribution' => $bySite[$s['id']]['contribution'] ?? null], $sel['sites']),
            'installation' => $installation,
            'breakdown' => $breakdown,
            'entries' => array_map(fn ($e) => [
                'id' => $e['id'], 'date' => $e['date'], 'siteId' => $e['site_id'], 'installation' => $e['installation'],
                'site' => $e['installation'] ? self::INSTALLATION : ($e['site_id'] ? ($sites[$e['site_id']]['name'] ?? '—') : 'Contrat'),
                'progress' => $e['progress'], 'cumulative' => Metrics::pct($cum[$e['id']] ?? 0), 'realization' => $e['realization'], 'observation' => $e['observation'],
                'esPerformance' => $e['es_performance'] ?? '',
            ], array_slice($entries, $offset, $size)),
            // Dernière performance environnementale et sociale renseignée (proposée à la saisie suivante).
            'esPerformance' => (string) (array_values(array_filter(array_column($entries, 'es_performance')))[0] ?? ''),
            'esLevels' => self::ES_LEVELS,
            'meta' => ['page' => $page, 'pages' => $pages, 'total' => count($entries), 'size' => $size],
            'evolution' => Metrics::progressEvolution($sel),
            'siteEvolution' => Metrics::siteEvolution($sel, $prev, $today), 'sitePrevLabel' => Dates::fr($prev), 'siteCurLabel' => Dates::fr($today),
        ];
    }

    private function parseProgress(Request $r, string $field = 'progress', string $what = 'L’avancement'): float
    {
        $p = Dates::decimal($r->input($field));
        abort_if($p === null, 422, "{$what} est invalide. Saisissez un nombre entre 0 et 100, par exemple 12,5.");
        abort_if($p < 0 || $p > 100, 422, "{$what} doit être compris entre 0 et 100 %.");
        return $p;
    }

    /**
     * Nouvelle situation : le taux de la période d'un site et, dans la même saisie, celui de l'installation de chantier et des
     * travaux préparatoires (installation_progress), cumulatif et plafonné à 100 % (décision du 10/11/2026). L'un ou
     * l'autre, ou les deux, enregistrés ensemble à la même date. site_id = INSTALLATION reste accepté pour l'installation seule.
     */
    public function store(Request $r)
    {
        Ctx::need('can_manage_progress');
        $c = $this->contractOr404((int) $r->input('contract_id'));
        abort_if(GoodsProcedure::stockOnly($c['goods_procedure']), 422, GoodsProcedure::STOCK_ONLY_MESSAGE);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut plus recevoir de nouvelle situation.');
        $date = Dates::valid($r->input('date')) ? Dates::part($r->input('date')) : null;
        abort_unless($date, 422, 'La date de la situation est obligatoire.');

        $installationOnly = $r->input('site_id') === 'INSTALLATION';
        $siteId = $installationOnly ? null : ((int) $r->input('site_id', 0) ?: null);
        $siteRate = !$installationOnly && trim((string) $r->input('progress', '')) !== '' ? $this->parseProgress($r) : null;
        $installRate = $installationOnly ? $this->parseProgress($r, 'progress', 'Le taux de l’installation de chantier')
            : (trim((string) $r->input('installation_progress', '')) !== '' ? $this->parseProgress($r, 'installation_progress', 'Le taux de l’installation de chantier') : null);
        if ($installRate !== null && $installRate <= 0) $installRate = null; // 0 : rien à ajouter à l'installation

        $rows = [];
        $labels = [];
        // L'installation de chantier et les travaux préparatoires concernent tout le contrat : leur taux se renseigne toujours,
        // son poids dans l'avancement étant son coût ÷ le montant du contrat.
        if ($installRate !== null) {
            abort_unless(Metrics::hasInstallation($c), 422, 'L’installation de chantier et les travaux préparatoires ne concernent que les contrats de TRAVAUX.');
            $current = Metrics::installationProgress($c);
            abort_if($current >= 100, 422, 'L’installation de chantier est déjà terminée à 100 %.');
            abort_if($current + $installRate > 100, 422, 'Il reste ' . self::remaining($current) . ' % à réaliser sur l’installation de chantier.');
            $rows[] = ['contract_site_id' => null, 'is_installation' => true, 'progress' => $installRate];
            $labels[] = self::INSTALLATION . " {$installRate} %";
        }
        if ($siteId) {
            $site = null;
            foreach ($c['sites'] as $s) if ($s['id'] === $siteId) $site = $s;
            abort_unless($site, 404, 'Site introuvable pour ce contrat.');
            abort_if($siteRate === null, 422, "Saisissez le taux de la période du site « {$site['name']} ».");
            $current = array_sum(array_column($site['entries'], 'progress'));
            abort_if($current >= 100, 422, 'Cet ouvrage/site est déjà terminé à 100 %.');
            abort_if($current + $siteRate > 100, 422, 'Il reste ' . self::remaining($current) . ' % à réaliser sur ce site.');
            $rows[] = ['contract_site_id' => $siteId, 'is_installation' => false, 'progress' => $siteRate];
            $labels[] = "site « {$site['name']} » {$siteRate} %";
        } elseif ($siteRate !== null) {
            abort_if((bool) $c['sites'], 422, 'Sélectionnez l’ouvrage / le site concerné.');
            $rows[] = ['contract_site_id' => null, 'is_installation' => false, 'progress' => $siteRate]; // contrat sans site
            $labels[] = "contrat {$siteRate} %";
        }
        abort_unless($rows, 422, $c['sites'] ? 'Saisissez le taux de la période d’un site, ou celui de l’installation de chantier.' : 'Saisissez l’avancement de la période.');

        // La réalisation physique, l'observation et la performance environnementale et sociale vont à la situation du site
        // (sinon à celle de l'installation).
        $es = trim((string) $r->input('es_performance', ''));
        abort_unless($es === '' || in_array($es, self::ES_LEVELS, true), 422, 'Performance environnementale et sociale : choisissez Bon, Moyen ou Mauvais.');
        $notes = ['realization_physical' => trim((string) $r->input('realization_physical', '')), 'observation' => trim((string) $r->input('observation', '')), 'es_performance' => $es ?: null];
        $ids = DB::transaction(function () use ($rows, $c, $date, $notes) {
            $ids = [];
            foreach ($rows as $i => $row) {
                $ids[] = DB::table('progress_entries')->insertGetId($row + [
                    'contract_id' => $c['id'], 'situation_date' => $date, 'paid_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
                ] + ($i === count($rows) - 1 ? $notes : ['realization_physical' => '', 'observation' => '', 'es_performance' => null]));
            }
            return $ids;
        });
        // Avancement du contrat après la saisie, pour le message et l'audit.
        $after = Metrics::pct(Metrics::progress(Loader::contract(Ctx::projectId(), Ctx::phaseId(), $c['id'], true)));
        foreach ($ids as $i => $id) {
            Ctx::audit('CREATION', 'ProgressEntry', $id, "Situation du contrat {$c['number']} du " . Dates::fr($date) . " : {$labels[$i]}. Avancement physique du contrat : {$after} %.");
        }
        $what = count($ids) > 1 ? 'Situations d’avancement enregistrées (site et installation de chantier)' : 'Situation d’avancement enregistrée';
        return response()->json(['message' => "{$what}. Avancement physique du contrat : " . number_format($after, 2, ',', ' ') . ' %.', 'id' => end($ids), 'ids' => $ids, 'progress' => $after], 201);
    }

    private function entryOr404(int $id): array
    {
        $e = DB::table('progress_entries')->where('id', $id)->first();
        abort_unless($e, 404, 'Situation introuvable.');
        $c = $this->contractOr404((int) $e->contract_id);
        return [$e, $c];
    }

    public function update(Request $r, int $id)
    {
        Ctx::need('can_manage_progress');
        [$e, $c] = $this->entryOr404($id);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut plus recevoir de modification de suivi.');
        $p = $this->parseProgress($r);
        $date = Dates::valid($r->input('date')) ? Dates::part($r->input('date')) : null;
        abort_unless($date, 422, 'La date de la situation est obligatoire.');
        if ($e->contract_site_id) {
            $previous = (float) DB::table('progress_entries')->where('contract_site_id', $e->contract_site_id)->where('id', '!=', $id)->sum('progress');
            abort_if($previous + $p > 100, 422, 'La correction dépasse 100 % sur ce site. Il reste ' . self::remaining($previous) . ' % disponible.');
        } elseif (!empty($e->is_installation)) {
            $previous = (float) DB::table('progress_entries')->where('contract_id', $e->contract_id)->where('is_installation', true)->where('id', '!=', $id)->sum('progress');
            abort_if($previous + $p > 100, 422, 'La correction dépasse 100 % sur l’installation de chantier. Il reste ' . self::remaining($previous) . ' % disponible.');
        }
        $es = $r->has('es_performance') ? trim((string) $r->input('es_performance', '')) : (string) ($e->es_performance ?? '');
        abort_unless($es === '' || in_array($es, self::ES_LEVELS, true), 422, 'Performance environnementale et sociale : choisissez Bon, Moyen ou Mauvais.');
        $old = 'date ' . Dates::fr(Dates::part($e->situation_date)) . ', avancement ' . (float) $e->progress . ' %';
        DB::table('progress_entries')->where('id', $id)->update([
            'situation_date' => $date, 'progress' => $p, 'realization_physical' => trim((string) $r->input('realization_physical', '')),
            'observation' => trim((string) $r->input('observation', '')), 'es_performance' => $es ?: null, 'updated_at' => now(),
        ]);
        Ctx::audit('MODIFICATION', 'ProgressEntry', $id, "Contrat {$c['number']}. Ancienne situation : {$old}. Nouvelle : date " . Dates::fr($date) . ", avancement {$p} %.");
        return ['message' => 'La situation d’avancement a été corrigée.'];
    }

    public function destroy(Request $r, int $id)
    {
        Ctx::need('can_manage_progress');
        $reason = trim((string) $r->input('reason', ''));
        abort_if(mb_strlen($reason) < 5, 422, 'Une raison valable (minimum 5 caractères) est obligatoire pour supprimer une situation.');
        [$e, $c] = $this->entryOr404($id);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne permet plus la suppression d’une situation.');
        DB::table('progress_entries')->where('id', $id)->delete();
        Ctx::audit('SUPPRESSION', 'ProgressEntry', $id, "Contrat {$c['number']}. Situation du " . Dates::fr(Dates::part($e->situation_date)) . ", avancement " . (float) $e->progress . " %. Motif : {$reason}");
        return ['message' => 'La situation d’avancement a été supprimée.'];
    }
}
