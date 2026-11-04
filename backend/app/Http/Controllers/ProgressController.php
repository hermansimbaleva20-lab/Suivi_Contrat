<?php
namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use App\Support\Loader;
use App\Support\Metrics;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProgressController extends Controller
{
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
        // Cumul par site à chaque saisie (ordre chronologique)
        $cum = [];
        $running = [];
        foreach ($sel['entries'] as $e) {
            $k = $e['site_id'] ?: 0;
            $running[$k] = ($running[$k] ?? 0) + $e['progress'];
            $cum[$e['id']] = min(100, $running[$k]);
        }
        $entries = array_reverse($sel['entries']);
        $size = 7;
        [$page, $pages, $offset] = Views::paginate(count($entries), (int) $r->query('page', 1), $size);
        $today = Dates::today();
        $prev = Dates::addMonths($today, -1);
        return [
            'contracts' => $list,
            'selected' => Views::contract($sel, true),
            'sites' => array_map(fn ($s) => Views::site($s) + ['remaining' => max(0, 100 - Metrics::siteProgress($s))], $sel['sites']),
            'entries' => array_map(fn ($e) => [
                'id' => $e['id'], 'date' => $e['date'], 'siteId' => $e['site_id'], 'site' => $e['site_id'] ? ($sites[$e['site_id']]['name'] ?? '—') : 'Contrat',
                'progress' => $e['progress'], 'cumulative' => Metrics::pct($cum[$e['id']] ?? 0), 'realization' => $e['realization'], 'observation' => $e['observation'],
            ], array_slice($entries, $offset, $size)),
            'meta' => ['page' => $page, 'pages' => $pages, 'total' => count($entries), 'size' => $size],
            'evolution' => Metrics::progressEvolution($sel),
            'siteEvolution' => Metrics::siteEvolution($sel, $prev, $today), 'sitePrevLabel' => Dates::fr($prev), 'siteCurLabel' => Dates::fr($today),
        ];
    }

    private function parseProgress(Request $r): float
    {
        $p = Dates::decimal($r->input('progress'));
        abort_if($p === null, 422, 'Avancement invalide. Saisissez un nombre entre 0 et 100, par exemple 12,5.');
        abort_if($p < 0 || $p > 100, 422, 'L’avancement doit être compris entre 0 et 100 %.');
        return $p;
    }

    public function store(Request $r)
    {
        Ctx::need('can_manage_progress');
        $c = $this->contractOr404((int) $r->input('contract_id'));
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut plus recevoir de nouvelle situation.');
        $p = $this->parseProgress($r);
        $date = Dates::valid($r->input('date')) ? Dates::part($r->input('date')) : null;
        abort_unless($date, 422, 'La date de la situation est obligatoire.');
        $siteId = (int) $r->input('site_id', 0) ?: null;
        if ($c['sites'] && !$siteId) abort(422, 'Sélectionnez l’ouvrage / le site concerné.');
        if ($siteId) {
            $site = null;
            foreach ($c['sites'] as $s) if ($s['id'] === $siteId) $site = $s;
            abort_unless($site, 404, 'Site introuvable pour ce contrat.');
            $current = array_sum(array_column($site['entries'], 'progress'));
            abort_if($current >= 100, 422, 'Cet ouvrage/site est déjà terminé à 100 %.');
            abort_if($current + $p > 100, 422, 'Il reste ' . rtrim(rtrim(number_format(100 - $current, 2, ',', ''), '0'), ',') . ' % à réaliser sur ce site.');
        }
        $id = DB::table('progress_entries')->insertGetId([
            'contract_id' => $c['id'], 'contract_site_id' => $siteId, 'situation_date' => $date, 'progress' => $p, 'paid_amount' => 0,
            'realization_physical' => trim((string) $r->input('realization_physical', '')), 'observation' => trim((string) $r->input('observation', '')), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Ctx::audit('CREATION', 'ProgressEntry', $id, "Situation du contrat {$c['number']} : " . Dates::fr($date) . ", avancement {$p} %.");
        return response()->json(['message' => 'Situation d’avancement enregistrée.', 'id' => $id], 201);
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
            abort_if($previous + $p > 100, 422, 'La correction dépasse 100 % sur ce site. Il reste ' . rtrim(rtrim(number_format(max(0, 100 - $previous), 2, ',', ''), '0'), ',') . ' % disponible.');
        }
        $old = 'date ' . Dates::fr(Dates::part($e->situation_date)) . ', avancement ' . (float) $e->progress . ' %';
        DB::table('progress_entries')->where('id', $id)->update([
            'situation_date' => $date, 'progress' => $p, 'realization_physical' => trim((string) $r->input('realization_physical', '')),
            'observation' => trim((string) $r->input('observation', '')), 'updated_at' => now(),
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
