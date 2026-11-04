<?php

namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Journal d'audit (TDR §12) : qui a saisi ou modifié quoi, et quand. Filtrable par personne, action, type d'élément,
 * période et texte ; synthèse de l'activité de chaque compte ; valeurs avant/après ; export Excel du résultat filtré.
 * Accès : can_manage_users | can_manage_settings (route).
 */
class AuditController extends Controller
{
    /** Libellés lisibles des types d'éléments journalisés. */
    private const ENTITIES = [
        'Contract' => 'Contrat', 'ContractPayment' => 'Paiement', 'ProgressEntry' => 'Avancement', 'PerformanceGuarantee' => 'Garantie', 'Company' => 'Entreprise',
        'User' => 'Compte', 'OrganizationalUnit' => 'Unité', 'Position' => 'Poste', 'StockArticle' => 'Article de stock', 'StockMovement' => 'Mouvement de stock',
        'FleetAsset' => 'Engin', 'FleetLog' => 'Carnet de bord', 'FleetMaintenance' => 'Entretien', 'FleetInsurance' => 'Assurance', 'ContractAdvance' => 'Avance de démarrage', 'Decompte' => 'Décompte',
        'ReportSnapshot' => 'État figé', 'AlertRecipient' => 'Destinataire d’alertes', 'AlertRule' => 'Séquence d’alertes', 'AlertDispatch' => 'Envoi d’alertes', 'AlertTemplate' => 'Modèle de message', 'AiSettings' => 'Paramètres IA',
    ];

    private function query(Request $r)
    {
        $q = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->leftJoin('organizational_units as o', 'o.id', '=', 'a.organizational_unit_id')
            ->where('a.project_id', Ctx::projectId())->select('a.*', 'u.full_name as user_name', 'u.email as user_email', 'o.name as unit_name');
        // Chaque unité ne consulte que les opérations faites en son sein ; la vue nationale voit tout.
        $units = Ctx::readableUnitIds();
        if ($units !== null) $q->whereIn('a.organizational_unit_id', $units ?: [0]);
        if ($r->filled('user_id')) $r->query('user_id') === 'system' ? $q->whereNull('a.user_id') : $q->where('a.user_id', (int) $r->query('user_id'));
        if ($r->filled('action')) $q->where('a.action', $r->query('action'));
        if ($r->filled('entity')) $q->where('a.entity_name', $r->query('entity'));
        if (Dates::valid($r->query('from'))) $q->where('a.created_at', '>=', Dates::part($r->query('from')) . ' 00:00:00');
        if (Dates::valid($r->query('to'))) $q->where('a.created_at', '<=', Dates::part($r->query('to')) . ' 23:59:59');
        if ($r->filled('q')) {
            $term = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr(trim($r->query('q')), 0, 100)) . '%';
            $q->where(fn ($w) => $w->where('a.details', 'like', $term)->orWhere('a.entity_id', 'like', $term));
        }
        return $q;
    }

    private static function row(object $a): array
    {
        $decode = fn ($v) => $v === null ? null : json_decode($v, true);
        return [
            'id' => $a->id, 'date' => substr((string) $a->created_at, 0, 19), 'userId' => $a->user_id ? (int) $a->user_id : null,
            'user' => $a->user_name ?: ($a->user_id ? 'Compte supprimé' : 'Système (envoi planifié)'), 'email' => $a->user_email, 'unit' => $a->unit_name,
            'action' => $a->action, 'entity' => self::ENTITIES[$a->entity_name] ?? $a->entity_name, 'entityName' => $a->entity_name, 'entityId' => $a->entity_id,
            'details' => (string) $a->details, 'before' => $decode($a->old_values ?? null), 'after' => $decode($a->new_values ?? null), 'machine' => $a->machine_name,
        ];
    }

    public function index(Request $r)
    {
        $q = $this->query($r)->orderByDesc('a.id');
        $total = (clone $q)->count();
        $size = 30;
        $pages = max(1, (int) ceil($total / $size));
        $page = max(1, min((int) $r->query('page', 1), $pages));
        $pid = Ctx::projectId();
        return [
            'data' => $q->offset(($page - 1) * $size)->limit($size)->get()->map(fn ($a) => self::row($a))->values(),
            'meta' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'size' => $size],
            'actions' => DB::table('audit_logs')->where('project_id', $pid)->distinct()->orderBy('action')->pluck('action'),
            'entities' => DB::table('audit_logs')->where('project_id', $pid)->distinct()->orderBy('entity_name')->pluck('entity_name')
                ->map(fn ($e) => ['key' => $e, 'label' => self::ENTITIES[$e] ?? $e])->values(),
        ];
    }

    /** Activité par compte sur la période filtrée : nombre d'opérations, dernière activité, opérations les plus fréquentes. */
    public function users(Request $r)
    {
        $base = $this->query($r->merge(['user_id' => null]));
        $rows = (clone $base)->groupBy('a.user_id', 'u.full_name', 'u.email')
            ->select(DB::raw('a.user_id, u.full_name AS user_name, u.email AS user_email, COUNT(*) AS n, MAX(a.created_at) AS last_at'))->orderByDesc('n')->get();
        $top = (clone $base)->groupBy('a.user_id', 'a.action')->select(DB::raw('a.user_id, a.action, COUNT(*) AS n'))->orderByDesc('n')->get()->groupBy(fn ($x) => (string) $x->user_id);
        return $rows->map(fn ($u) => [
            'userId' => $u->user_id ? (int) $u->user_id : 'system', 'user' => $u->user_name ?: ($u->user_id ? 'Compte supprimé' : 'Système (envoi planifié)'), 'email' => $u->user_email,
            'count' => (int) $u->n, 'lastAt' => substr((string) $u->last_at, 0, 16),
            'topActions' => ($top[(string) $u->user_id] ?? collect())->take(3)->map(fn ($x) => ['action' => $x->action, 'count' => (int) $x->n])->values(),
        ])->values();
    }

    public function export(Request $r)
    {
        $rows = $this->query($r)->orderByDesc('a.id')->limit(5000)->get()->map(fn ($a) => self::row($a));
        $H = Xlsx::HEADER; $T = Xlsx::TEXT;
        $sheet = [[['v' => 'Journal d’audit — extrait du ' . Dates::fr(Dates::today()), 's' => Xlsx::TITLE]], [],
            array_map(fn ($h) => ['v' => $h, 's' => $H], ['Date', 'Utilisateur', 'Adresse', 'Unité', 'Action', 'Élément', 'N°', 'Détails'])];
        foreach ($rows as $x) $sheet[] = array_map(fn ($v) => ['v' => (string) $v, 's' => $T], [$x['date'], $x['user'], $x['email'], $x['unit'], $x['action'], $x['entity'], $x['entityId'], $x['details']]);
        $bin = Xlsx::build([['name' => 'Journal d’audit', 'rows' => $sheet, 'widths' => [19, 24, 28, 22, 22, 20, 10, 90], 'freezeRows' => 3]]);
        Ctx::audit('EXPORT_JOURNAL', 'AuditLog', '', 'Export Excel du journal d’audit (' . count($rows) . ' ligne(s)).');
        return response($bin, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="journal_audit.xlsx"']);
    }
}
