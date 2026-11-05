<?php
namespace App\Http\Controllers;

use App\Support\AlertEngine;
use App\Support\AlertTemplates;
use App\Support\Ctx;
use App\Support\Dates;
use App\Support\GuaranteeAlerts;
use App\Support\GoodsProcedure;
use App\Support\Loader;
use App\Support\MailService;
use App\Support\Metrics;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Page Alertes : contrats en vigilance, en retard ou à 100 %, sites à 100 %, garanties à surveiller ; envoi manuel des
 * messages aux entreprises d'après les modèles du projet (AlertTemplates) ; modèles modifiables ; historique des envois
 * filtré par contrat ou par n° de garantie.
 */
class AlertController extends Controller
{
    private function compute(): array
    {
        $cs = Loader::contracts(Ctx::projectId(), Ctx::phaseId());
        $out = ['retards' => [], 'vigilances' => [], 'sites' => [], 'contracts' => []];
        foreach ($cs as $c) {
            if ($c['terminated']) continue;
            $status = Metrics::status($c);
            if ($status === 'Retard') $out['retards'][] = $c;
            if ($status === 'Vigilance') $out['vigilances'][] = $c;
            if (Metrics::progress($c) >= 100) $out['contracts'][] = $c;
            foreach ($c['sites'] as $s) if (Metrics::siteProgress($s) >= 100) $out['sites'][] = ['site' => $s, 'contract' => $c];
        }
        usort($out['retards'], fn ($a, $b) => strcmp((string) $a['end_date'], (string) $b['end_date']));
        return $out;
    }

    private static function contractRow(array $c): array
    {
        return ['id' => $c['id'], 'number' => $c['number'], 'company' => $c['company'], 'progress' => Metrics::pct(Metrics::progress($c)), 'elapsed' => Metrics::pct(Metrics::elapsed($c, Dates::today())), 'endDate' => $c['end_date']];
    }

    public function index()
    {
        $out = ['guarantees' => [], 'retards' => [], 'vigilances' => [], 'sites' => [], 'contracts' => [], 'logistics' => []];
        if (Ctx::can('can_view_alerts_guarantees')) {
            $guarantees = array_values(array_filter(array_map(fn ($g) => Views::guarantee($g), Loader::guaranteeRows(['project_id' => Ctx::projectId(), 'phase_id' => Ctx::phaseId()])), fn ($g) => $g['needsAttention']));
            usort($guarantees, fn ($x, $y) => ((int) $y['isExpired'] <=> (int) $x['isExpired']) ?: strcmp($x['endDate'], $y['endDate']));
            $out['guarantees'] = $guarantees;
        }
        if (Ctx::can('can_view_alerts_contracts')) {
            $a = $this->compute();
            $out['retards'] = array_map([self::class, 'contractRow'], $a['retards']);
            $out['vigilances'] = array_map([self::class, 'contractRow'], $a['vigilances']);
            $out['sites'] = array_map(fn ($x) => ['id' => $x['site']['id'], 'name' => $x['site']['name'], 'contractId' => $x['contract']['id'], 'contractNumber' => $x['contract']['number']], $a['sites']);
            $out['contracts'] = array_map(fn ($c) => ['id' => $c['id'], 'number' => $c['number'], 'company' => $c['company'], 'category' => $c['category'], 'endDate' => $c['end_date']], $a['contracts']);
        }
        if (Ctx::can('can_view_alerts_logistics')) {
            $out['logistics'] = array_map(fn ($a) => array_intersect_key($a, array_flip(['category', 'key', 'level', 'code', 'itemId', 'name', 'unitId', 'unitName', 'text'])), AlertEngine::logistics(Ctx::projectId(), Ctx::readableUnitIds()));
        }
        return $out;
    }

    /**
     * Message d'un envoi manuel : garantie (vigilance ou retard selon l'échéance), contrat (vigilance, retard,
     * satisfaction) adressés à l'entreprise ; site à 100 % adressé à l'équipe.
     */
    private function compose(string $type, int $id): array
    {
        $pid = Ctx::projectId();
        $templates = AlertTemplates::all($pid);
        if ($type === 'garantie') {
            $row = collect(GuaranteeAlerts::plan($pid))->firstWhere('id', $id);
            abort_unless($row, 404, 'Garantie introuvable dans la phase en cours.');
            $kind = $row['kind'] ?? GuaranteeAlerts::VIGILANCE;
            $msg = GuaranteeAlerts::message($kind, $row['vars'], $templates["GARANTIE_{$kind}"]);
            return $msg + ['email' => $row['email'], 'company' => $row['company'], 'category' => 'GARANTIE',
                'key' => $row['prefix'] . substr($kind, 0, 1), 'type' => $kind, 'contractId' => $row['contractId'], 'guaranteeId' => $id, 'guaranteeNumber' => $row['guaranteeNumber'],
                'audit' => ['ALERTE_GARANTIE', 'PerformanceGuarantee', $row['contractNumber']]];
        }
        if (in_array($type, ['vigilance', 'retard', 'contrat'], true)) {
            $c = Loader::contract($pid, Ctx::phaseId(), $id, true);
            abort_unless($c, 404, 'Contrat introuvable.');
            abort_if(GoodsProcedure::stockOnly($c['goods_procedure']), 422, GoodsProcedure::STOCK_ONLY_MESSAGE);
            $kind = ['vigilance' => 'VIGILANCE', 'retard' => 'RETARD', 'contrat' => 'SATISFACTION'][$type];
            $msg = AlertTemplates::render($templates["CONTRAT_{$kind}"], AlertEngine::contractVars($c, $pid));
            $email = trim((string) DB::table('companies')->where('id', $c['company_id'])->value('email'));
            return $msg + ['email' => $email, 'company' => $c['company'], 'category' => 'CONTRAT',
                'key' => "CONTRAT:{$id}:{$kind}", 'type' => $kind, 'contractId' => $id, 'guaranteeId' => null, 'guaranteeNumber' => null,
                'audit' => [$kind === 'SATISFACTION' ? 'ALERTE_SATISFACTION_CONTRAT' : "ALERTE_{$kind}", 'Contract', $c['number']]];
        }
        if ($type === 'site') {
            $row = DB::table('contract_sites as s')->join('contracts as c', 'c.id', '=', 's.contract_id')->where('s.id', $id)->where('c.project_id', $pid)->select('s.name', 'c.reference', 'c.id as contract_id')->first();
            abort_unless($row, 404, 'Site introuvable.');
            $team = DB::table('alert_recipients')->where('project_id', $pid)->where('is_active', true)->pluck('email')->all();
            return ['subject' => "Satisfaction — site {$row->name} à 100 %", 'text' => "Notification de satisfaction\n\nLe site « {$row->name} » du contrat {$row->reference} a atteint 100 % de réalisation.",
                'email' => null, 'team' => $team, 'company' => null, 'category' => 'CONTRAT', 'key' => "SITE:{$id}", 'type' => 'SATISFACTION', 'contractId' => (int) $row->contract_id,
                'guaranteeId' => null, 'guaranteeNumber' => null, 'audit' => ['ALERTE_SATISFACTION_SITE', 'ContractSite', $row->name]];
        }
        abort(422, 'Type d’alerte inconnu.');
    }

    /**
     * Envoi MANUEL (ou aperçu avec preview=1) d'un message. type : garantie | vigilance | retard | contrat | site.
     * Les messages aux entreprises partent à la seule adresse de leur fiche ; l'envoi est tracé dans l'historique et, pour
     * une garantie, compte dans la séquence des relances.
     */
    public function send(Request $r)
    {
        $type = (string) $r->input('type');
        $id = (int) $r->input('id');
        $pid = Ctx::projectId();
        Ctx::need($type === 'garantie' ? 'can_view_alerts_guarantees' : 'can_view_alerts_contracts');
        $m = $this->compose($type, $id);
        $to = $type === 'site' ? $m['team'] : ($m['email'] && filter_var($m['email'], FILTER_VALIDATE_EMAIL) ? [$m['email']] : []);
        if ($r->boolean('preview')) {
            return ['subject' => $m['subject'], 'text' => $m['text'], 'to' => $to, 'company' => $m['company'], 'guaranteeNumber' => $m['guaranteeNumber']];
        }
        abort_if(!$to && $type !== 'site', 422, "L’entreprise {$m['company']} n’a pas d’adresse e-mail valide : complétez sa fiche dans Entreprises.");
        $res = MailService::send($pid, $m['subject'], $m['text'], $to);
        AlertEngine::record($pid, ['category' => $m['category'], 'key' => $m['key'], 'subject' => $m['subject'], 'recipients' => $to,
            'status' => $res['success'] ? 'ENVOYE' : 'ECHEC', 'message' => $res['message'], 'trigger' => 'MANUEL', 'userId' => (int) Ctx::user()->id, 'contractId' => $m['contractId'],
            'guaranteeId' => $m['guaranteeId'], 'guaranteeNumber' => $m['guaranteeNumber'], 'type' => $m['type'], 'body' => $m['text']]);
        abort_unless($res['success'], 422, $res['message']);
        [$action, $entity, $label] = $m['audit'];
        Ctx::audit($action, $entity, $id, "Message « {$m['subject']} » ({$label}) envoyé manuellement. {$res['message']}");
        return ['message' => $res['message']];
    }

    private static function canEditTemplates(): bool
    {
        return Ctx::canViewAlertHistory() && Ctx::isAdmin() && Ctx::isNationalCoordination();
    }

    /** Modèles des messages, avec les champs disponibles et un exemple de chacun. */
    public function templates()
    {
        $pid = Ctx::projectId();
        $seeGuarantees = Ctx::can('can_view_alerts_guarantees');
        $seeContracts = Ctx::can('can_view_alerts_contracts');
        abort_unless(Ctx::canViewAlertHistory() && ($seeGuarantees || $seeContracts), 403, 'Vous n’êtes pas habilité à consulter ces modèles.');
        $saved = DB::table('alert_templates as t')->leftJoin('users as u', 'u.id', '=', 't.updated_by')->where('t.project_id', $pid)->get(['t.code', 't.updated_at', 'u.full_name'])->keyBy('code');
        $all = AlertTemplates::all($pid);
        $out = [];
        foreach (AlertTemplates::LABELS as $code => $label) {
            if (!(AlertTemplates::group($code) === 'GARANTIE' ? $seeGuarantees : $seeContracts)) continue;
            $s = $saved[$code] ?? null;
            $fields = [];
            foreach (AlertTemplates::fields($code) as $k => [$l, $ex]) $fields[] = ['key' => $k, 'label' => $l, 'example' => $ex];
            $out[] = ['code' => $code, 'group' => AlertTemplates::isStaff($code) ? 'PERSONNEL' : AlertTemplates::group($code), 'about' => AlertTemplates::group($code), 'label' => $label, 'when' => AlertTemplates::WHEN[$code],
                'subject' => $all[$code]['subject'], 'body' => $all[$code]['body'], 'fields' => $fields, 'isDefault' => !$s, 'updatedAt' => $s ? substr((string) $s->updated_at, 0, 16) : null, 'updatedBy' => $s->full_name ?? null];
        }
        return ['templates' => $out, 'canEdit' => self::canEditTemplates()];
    }

    private function needTemplateCode(string $code): void
    {
        abort_unless(self::canEditTemplates(), 403, 'Les modèles des messages sont modifiés par un administrateur de la coordination nationale habilité à les consulter, ou le super-administrateur.');
        abort_unless(isset(AlertTemplates::DEFAULTS[$code]), 404, 'Modèle inconnu.');
    }

    public function updateTemplate(Request $r, string $code)
    {
        $this->needTemplateCode($code);
        $d = $r->validate(['subject' => 'required|string|max:255', 'body' => 'required|string|min:20|max:5000'], [], ['subject' => 'objet', 'body' => 'texte du message']);
        $unknown = AlertTemplates::unknownFields($code, $d['subject'] . ' ' . $d['body']);
        if ($unknown) {
            return response()->json(['message' => 'Champ inconnu : {' . implode('}, {', $unknown) . '}.', 'errors' => ['body' => ['Champ(s) inconnu(s) : {' . implode('}, {', $unknown) . '}. Utilisez les champs proposés.']]], 422);
        }
        $pid = Ctx::projectId();
        $before = AlertTemplates::all($pid)[$code];
        DB::table('alert_templates')->updateOrInsert(['project_id' => $pid, 'code' => $code],
            ['subject' => trim($d['subject']), 'body' => rtrim($d['body']), 'updated_by' => Ctx::user()->id, 'updated_at' => now(), 'created_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'AlertTemplate', $code, 'Modèle « ' . AlertTemplates::LABELS[$code] . ' » modifié.', $before, ['subject' => trim($d['subject']), 'body' => rtrim($d['body'])]);
        return ['message' => 'Modèle « ' . AlertTemplates::LABELS[$code] . ' » enregistré : il s’applique aux prochains envois.'];
    }

    public function resetTemplate(string $code)
    {
        $this->needTemplateCode($code);
        $pid = Ctx::projectId();
        $before = AlertTemplates::all($pid)[$code];
        DB::table('alert_templates')->where('project_id', $pid)->where('code', $code)->delete();
        Ctx::auditChange('MODIFICATION', 'AlertTemplate', $code, 'Modèle « ' . AlertTemplates::LABELS[$code] . ' » rétabli par défaut.', $before, AlertTemplates::DEFAULTS[$code]);
        return ['message' => 'Modèle par défaut rétabli.'];
    }

    /** Historique des messages envoyés, filtré par contrat, par garantie (n° de garantie), par nature ou par résultat. */
    public function history(Request $r)
    {
        $pid = Ctx::projectId();
        $seeGuarantees = Ctx::can('can_view_alerts_guarantees');
        $seeContracts = Ctx::can('can_view_alerts_contracts');
        abort_unless(Ctx::canViewAlertHistory() && ($seeGuarantees || $seeContracts), 403, 'Vous n’êtes pas habilité à consulter cet historique.');
        $q = DB::table('alert_dispatches as d')->leftJoin('contracts as c', 'c.id', '=', 'd.contract_id')->leftJoin('companies as co', 'co.id', '=', 'c.company_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.sent_by')->where('d.project_id', $pid)->whereNotNull('d.contract_id');
        if (!$seeGuarantees) $q->whereNull('d.performance_guarantee_id');
        if (!$seeContracts) $q->whereNotNull('d.performance_guarantee_id');
        if ($r->filled('contract_id')) $q->where('d.contract_id', (int) $r->input('contract_id'));
        if ($r->filled('guarantee_id')) $q->where('d.performance_guarantee_id', (int) $r->input('guarantee_id'));
        if ($r->input('scope') === 'GARANTIE') $q->whereNotNull('d.performance_guarantee_id');
        if ($r->input('scope') === 'CONTRAT') $q->whereNull('d.performance_guarantee_id');
        if (in_array($r->input('type'), ['VIGILANCE', 'RETARD', 'SATISFACTION', 'PERSONNEL', 'RAPPEL'], true)) $q->where('d.message_type', $r->input('type'));
        if (in_array($r->input('status'), ['ENVOYE', 'ECHEC'], true)) $q->where('d.status', $r->input('status'));
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('d.id')->limit(300)->get(['d.*', 'c.reference as contract_number', 'co.name as company', 'u.full_name as sent_by_name'])
            ->map(fn ($d) => [
                'id' => $d->id, 'date' => substr((string) $d->created_at, 0, 16), 'scope' => $d->performance_guarantee_id ? 'GARANTIE' : 'CONTRAT', 'type' => $d->message_type,
                'contractId' => (int) $d->contract_id, 'contractNumber' => (string) $d->contract_number, 'company' => (string) $d->company, 'guaranteeId' => $d->performance_guarantee_id ? (int) $d->performance_guarantee_id : null,
                'guaranteeNumber' => $d->guarantee_number, 'subject' => $d->subject, 'body' => $d->body, 'recipients' => $d->recipients, 'status' => $d->status, 'message' => (string) $d->message,
                'trigger' => $d->trigger, 'sentBy' => $d->sent_by_name,
            ])->values();
        // Listes de sélection : contrats et garanties du projet ayant au moins un envoi, plus ceux de la phase en cours.
        $contracts = DB::table('contracts as c')->leftJoin('companies as co', 'co.id', '=', 'c.company_id')->where('c.project_id', $pid)
            ->where(fn ($w) => $w->where('c.phase_id', Ctx::phaseId())->orWhereIn('c.id', DB::table('alert_dispatches')->where('project_id', $pid)->whereNotNull('contract_id')->select('contract_id')))
            ->orderBy('c.reference')->get(['c.id', 'c.reference', 'co.name'])->map(fn ($c) => ['id' => $c->id, 'number' => $c->reference, 'company' => (string) $c->name])->values();
        $guarantees = DB::table('performance_guarantees as g')->join('contracts as c', 'c.id', '=', 'g.contract_id')->whereIn('g.contract_id', $seeGuarantees ? $contracts->pluck('id') : [0])
            ->orderBy('g.account_number')->get(['g.id', 'g.account_number', 'g.contract_id', 'c.reference'])
            ->map(fn ($g) => ['id' => $g->id, 'number' => (string) $g->account_number, 'contractId' => (int) $g->contract_id, 'contractNumber' => $g->reference])->values();
        return ['data' => $rows, 'total' => $total, 'contracts' => $contracts, 'guarantees' => $guarantees];
    }
}
