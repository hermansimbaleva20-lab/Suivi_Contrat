<?php

namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use App\Support\ReportBuilder;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Rapports périodiques et historisation (TDR §11) : aperçu d'une période, états figés (immuables), comparaison de deux
 * états (ex. T1 contre T2), export Excel avec choix des colonnes. Consultation : can_view_reports | can_view_situation ;
 * figer un état : can_manage_settings ; supprimer un état : super-admin seulement, avec motif.
 */
class ReportSnapshotController extends Controller
{
    private function periodFrom(Request $r): array
    {
        $d = $r->validate([
            'period_type' => ['required', Rule::in(['TRIMESTRE', 'MOIS', 'ANNEE', 'LIBRE'])],
            'year' => 'nullable|integer|min:2000|max:2100',
            'index' => 'nullable|integer|min:1|max:12',
            'from' => 'nullable|required_if:period_type,LIBRE|date_format:Y-m-d',
            'to' => 'nullable|required_if:period_type,LIBRE|date_format:Y-m-d|after_or_equal:from',
        ], [], ['from' => 'date de début', 'to' => 'date de fin']);
        if ($d['period_type'] === 'TRIMESTRE' && ($d['index'] ?? 1) > 4) throw ValidationException::withMessages(['index' => 'Trimestre invalide.']);
        [$start, $end, $label] = ReportBuilder::period($d['period_type'], (int) ($d['year'] ?? substr(Dates::today(), 0, 4)), (int) ($d['index'] ?? 1), $d['from'] ?? null, $d['to'] ?? null);
        return [$d['period_type'], $start, $end, $label];
    }

    /** État calculé à la fin de la période (ou aujourd'hui si la période n'est pas terminée). */
    private function build(string $start, string $end): array
    {
        $asOf = min($end, Dates::today());
        $rows = ReportBuilder::contracts(Ctx::projectId(), Ctx::phaseId(), $asOf);
        return ['asOf' => $asOf, 'rows' => $rows, 'summary' => ReportBuilder::summary($rows), 'fuel' => ReportBuilder::fuel(Ctx::projectId(), $start, $asOf)];
    }

    private function snapshotOr404(int $id): object
    {
        $s = DB::table('report_snapshots as s')->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->where('s.id', $id)->where('s.project_id', Ctx::projectId())->select('s.*', 'u.full_name as created_by_name')->first();
        abort_unless($s, 404, 'État figé introuvable.');
        return $s;
    }

    private static function snapshotView(object $s, bool $withData = false): array
    {
        $data = json_decode($s->data, true);
        $v = [
            'id' => $s->id, 'label' => $s->label, 'periodType' => $s->period_type, 'start' => Dates::part($s->period_start), 'end' => Dates::part($s->period_end),
            'comment' => (string) $s->comment, 'createdAt' => substr((string) $s->created_at, 0, 16), 'createdBy' => $s->created_by_name ?? null,
            'asOf' => $data['asOf'], 'summary' => $data['summary'],
        ];
        return $withData ? $v + ['rows' => $data['rows'], 'fuel' => $data['fuel']] : $v;
    }

    public function index()
    {
        $list = DB::table('report_snapshots as s')->leftJoin('users as u', 'u.id', '=', 's.created_by')
            ->where('s.project_id', Ctx::projectId())->where('s.phase_id', Ctx::phaseId())
            ->orderByDesc('s.period_end')->orderByDesc('s.id')->select('s.*', 'u.full_name as created_by_name')->get();
        $today = Dates::today();
        return [
            'snapshots' => $list->map(fn ($s) => self::snapshotView($s))->values(),
            'columns' => collect(ReportBuilder::COLUMNS)->map(fn ($c, $k) => ['key' => $k, 'label' => $c[0]])->values(),
            'defaults' => ['year' => (int) substr($today, 0, 4), 'quarter' => intdiv((int) substr($today, 5, 2) - 1, 3) + 1, 'month' => (int) substr($today, 5, 2)],
            'canFreeze' => Ctx::can('can_manage_settings'),
            'canDelete' => (bool) Ctx::user()->is_super_admin,
        ];
    }

    /** Aperçu d'une période, sans l'enregistrer. */
    public function preview(Request $r)
    {
        [$type, $start, $end, $label] = $this->periodFrom($r);
        return ['label' => $label, 'periodType' => $type, 'start' => $start, 'end' => $end] + $this->build($start, $end);
    }

    /** Fige l'état de la période : il ne sera plus recalculé, même si les données sources changent ensuite. */
    public function store(Request $r)
    {
        Ctx::need('can_manage_settings');
        [$type, $start, $end, $label] = $this->periodFrom($r);
        $comment = trim((string) $r->input('comment', ''));
        if (mb_strlen($comment) > 2000) throw ValidationException::withMessages(['comment' => 'Le commentaire ne peut pas dépasser 2 000 caractères.']);
        if ($start > Dates::today()) throw ValidationException::withMessages(['period_type' => 'Cette période n’a pas encore commencé.']);
        $exists = DB::table('report_snapshots')->where('project_id', Ctx::projectId())->where('phase_id', Ctx::phaseId())->where('label', $label)->exists();
        if ($exists) throw ValidationException::withMessages(['period_type' => "L’état « {$label} » est déjà figé pour cette phase."]);
        $data = $this->build($start, $end);
        $id = DB::table('report_snapshots')->insertGetId([
            'project_id' => Ctx::projectId(), 'phase_id' => Ctx::phaseId(), 'period_type' => $type, 'label' => $label, 'period_start' => $start, 'period_end' => $end,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'comment' => $comment ?: null, 'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Ctx::audit('ETAT_FIGE', 'ReportSnapshot', $id, "État « {$label} » figé au " . Dates::fr($data['asOf']) . " ({$data['summary']['contracts']} contrat(s)).");
        return ['message' => "État « {$label} » figé. Il servira de référence pour les comparaisons.", 'id' => $id];
    }

    public function show(int $id)
    {
        return self::snapshotView($this->snapshotOr404($id), true);
    }

    /** Comparaison de deux états figés ; b = « live » compare avec la situation actuelle. */
    public function compare(Request $r)
    {
        $a = self::snapshotView($this->snapshotOr404((int) $r->query('a')), true);
        if ($r->query('b') === 'live') {
            $live = $this->build(Dates::today(), Dates::today());
            $b = ['id' => 'live', 'label' => 'Situation actuelle', 'asOf' => $live['asOf'], 'summary' => $live['summary'], 'rows' => $live['rows'], 'fuel' => $live['fuel']];
        } else {
            $b = self::snapshotView($this->snapshotOr404((int) $r->query('b')), true);
        }
        return [
            'a' => array_diff_key($a, ['rows' => 1]), 'b' => array_diff_key($b, ['rows' => 1]),
            'rows' => ReportBuilder::compare($a['rows'], $b['rows']),
        ];
    }

    /** Export Excel d'un état figé (?id) avec les colonnes choisies (?columns[]). */
    public function export(Request $r)
    {
        $s = $this->snapshotOr404((int) $r->query('id'));
        $v = self::snapshotView($s, true);
        $keys = array_values(array_intersect((array) $r->query('columns', array_keys(ReportBuilder::COLUMNS)), array_keys(ReportBuilder::COLUMNS))) ?: array_keys(ReportBuilder::COLUMNS);
        $style = ['money' => Xlsx::MONEY, 'pct' => Xlsx::MONEY, 'int' => Xlsx::TEXT, 'text' => Xlsx::TEXT];
        $rows = [[['v' => "Rapport {$v['label']} — situation au " . Dates::fr($v['asOf']), 's' => Xlsx::TITLE]]];
        if ($v['comment']) $rows[] = [['v' => $v['comment'], 's' => Xlsx::NORMAL]];
        $rows[] = [];
        $rows[] = array_map(fn ($k) => ['v' => ReportBuilder::COLUMNS[$k][0], 's' => Xlsx::HEADER], $keys);
        foreach ($v['rows'] as $row) $rows[] = array_map(fn ($k) => ['v' => $row[$k], 's' => $style[ReportBuilder::COLUMNS[$k][1]]], $keys);
        $f = $v['fuel'];
        $rows[] = [];
        $rows[] = [['v' => 'Carburant de la période (saisies autorisées)', 's' => Xlsx::BOLD]];
        $rows[] = [['v' => 'Véhicules : km', 's' => Xlsx::TEXT], ['v' => $f['vehicles']['distance'], 's' => Xlsx::MONEY], ['v' => 'litres', 's' => Xlsx::TEXT], ['v' => $f['vehicles']['litresPurchased'], 's' => Xlsx::MONEY], ['v' => 'USD', 's' => Xlsx::TEXT], ['v' => $f['vehicles']['purchaseCost'], 's' => Xlsx::MONEY]];
        $rows[] = [['v' => 'Groupes : heures', 's' => Xlsx::TEXT], ['v' => $f['generators']['distance'], 's' => Xlsx::MONEY], ['v' => 'litres', 's' => Xlsx::TEXT], ['v' => $f['generators']['litresPurchased'], 's' => Xlsx::MONEY], ['v' => 'USD', 's' => Xlsx::TEXT], ['v' => $f['generators']['purchaseCost'], 's' => Xlsx::MONEY]];
        $bin = Xlsx::build([['name' => mb_substr($v['label'], 0, 31), 'rows' => $rows, 'widths' => array_map(fn ($k) => $k === 'company' || $k === 'observation' ? 34 : 16, $keys), 'freezeRows' => $v['comment'] ? 4 : 3]]);
        Ctx::audit('EXPORT_RAPPORT', 'ReportSnapshot', $s->id, "Export Excel de l’état « {$v['label']} » (" . count($keys) . ' colonne(s)).');
        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="etat_' . preg_replace('/[^A-Za-z0-9]+/', '_', $v['label']) . '.xlsx"',
        ]);
    }

    public function destroy(Request $r, int $id)
    {
        abort_unless((bool) Ctx::user()->is_super_admin, 403, 'Seul le super-administrateur peut supprimer un état figé.');
        $d = $r->validate(['reason' => 'required|string|min:5|max:500'], [], ['reason' => 'motif']);
        $s = $this->snapshotOr404($id);
        DB::table('report_snapshots')->where('id', $id)->delete();
        Ctx::auditChange('SUPPRESSION', 'ReportSnapshot', $id, "État figé « {$s->label} » supprimé. Motif : {$d['reason']}", (array) $s, null);
        return ['message' => "État « {$s->label} » supprimé."];
    }
}
