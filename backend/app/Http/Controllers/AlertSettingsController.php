<?php

namespace App\Http\Controllers;

use App\Support\AlertEngine;
use App\Support\Ctx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Administration des alertes par e-mail (TDR §10), réservée au super-administrateur : adresses destinataires
 * (par catégorie et par unité), séquence d'envoi de chaque catégorie, simulation, envoi immédiat, historique des envois.
 */
class AlertSettingsController extends Controller
{
    private function needSuperAdmin(): void
    {
        abort_unless((bool) Ctx::user()->is_super_admin, 403, 'Les alertes par e-mail sont réglées par le super-administrateur.');
    }

    private static function recipientView(object $r): array
    {
        return ['id' => $r->id, 'email' => $r->email, 'name' => (string) $r->name, 'positionId' => $r->position_id ? (int) $r->position_id : null, 'position' => $r->position_name, 'unitId' => $r->organizational_unit_id ? (int) $r->organizational_unit_id : null,
            'categories' => array_values(array_intersect(explode(',', (string) $r->categories), array_keys(AlertEngine::CATEGORIES))), 'isActive' => (bool) $r->is_active];
    }

    public function index()
    {
        $this->needSuperAdmin();
        $pid = Ctx::projectId();
        foreach (array_keys(AlertEngine::CATEGORIES) as $cat) {
            if (!DB::table('alert_rules')->where('project_id', $pid)->where('category', $cat)->exists()) {
                DB::table('alert_rules')->insert(['project_id' => $pid, 'category' => $cat, 'is_enabled' => true, 'interval_days' => 7, 'max_sends' => 3, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $smtp = DB::table('smtp_settings')->where('project_id', $pid)->first();
        return [
            'categories' => collect(AlertEngine::CATEGORIES)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'recipients' => DB::table('alert_recipients as r')->leftJoin('positions as p', 'p.id', '=', 'r.position_id')->where('r.project_id', $pid)->orderBy('r.email')->get(['r.*', 'p.name as position_name'])->map(fn ($r) => self::recipientView($r))->values(),
            'rules' => DB::table('alert_rules')->where('project_id', $pid)->get()->map(fn ($r) => ['category' => $r->category, 'isEnabled' => (bool) $r->is_enabled, 'intervalDays' => (int) $r->interval_days, 'maxSends' => (int) $r->max_sends])->values(),
            'positions' => SettingsController::positionOptions($pid),
            'units' => DB::table('organizational_units')->where('project_id', $pid)->orderByRaw("CASE WHEN type = 'COORDINATION' THEN 0 ELSE 1 END")->orderBy('name')->get(['id', 'name']),
            'dispatches' => DB::table('alert_dispatches as d')->leftJoin('users as u', 'u.id', '=', 'd.sent_by')->where('d.project_id', $pid)->orderByDesc('d.id')->limit(60)
                ->get(['d.id', 'd.category', 'd.subject', 'd.recipients', 'd.status', 'd.message', 'd.trigger', 'd.created_at', 'u.full_name as sent_by'])
                ->map(fn ($d) => ['id' => $d->id, 'category' => $d->category, 'subject' => $d->subject, 'recipients' => $d->recipients, 'status' => $d->status, 'message' => (string) $d->message,
                    'trigger' => $d->trigger, 'date' => substr((string) $d->created_at, 0, 16), 'sentBy' => $d->sent_by])->values(),
            'smtpReady' => $smtp && trim((string) $smtp->host) !== '' && trim((string) $smtp->sender_email) !== '',
        ];
    }

    private function recipientData(Request $r, ?int $ignoreId = null): array
    {
        $pid = Ctx::projectId();
        $d = $r->validate([
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('alert_recipients', 'email')->where('project_id', $pid)->ignore($ignoreId)],
            'name' => 'required|string|max:150',
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->where('project_id', $pid)],
            'organizational_unit_id' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')->where('project_id', $pid)],
            'categories' => 'required|array|min:1',
            'categories.*' => ['string', Rule::in(array_keys(AlertEngine::CATEGORIES))],
        ], ['email.unique' => 'Cette adresse reçoit déjà les alertes : modifiez-la plutôt.', 'categories.required' => 'Choisissez au moins une catégorie d’alerte.', 'position_id.required' => 'Choisissez le poste occupé dans le projet.'],
            ['email' => 'adresse e-mail', 'name' => 'nom', 'position_id' => 'poste', 'organizational_unit_id' => 'unité']);
        return ['email' => strtolower(trim($d['email'])), 'name' => trim($d['name']), 'position_id' => (int) $d['position_id'], 'organizational_unit_id' => $d['organizational_unit_id'] ?? null,
            'categories' => implode(',', array_values(array_unique($d['categories'])))];
    }

    public function storeRecipient(Request $r)
    {
        $this->needSuperAdmin();
        $data = $this->recipientData($r);
        $id = DB::table('alert_recipients')->insertGetId($data + ['project_id' => Ctx::projectId(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Ctx::auditChange('CREATION', 'AlertRecipient', $id, "Destinataire d’alertes {$data['email']} ajouté.", null, $data);
        return ['message' => "{$data['email']} recevra les alertes choisies."];
    }

    private function recipientOr404(int $id): object
    {
        $r = DB::table('alert_recipients')->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($r, 404, 'Destinataire introuvable.');
        return $r;
    }

    public function updateRecipient(Request $r, int $id)
    {
        $this->needSuperAdmin();
        $before = $this->recipientOr404($id);
        $data = $this->recipientData($r, $id);
        DB::table('alert_recipients')->where('id', $id)->update($data + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'AlertRecipient', $id, "Destinataire d’alertes {$data['email']} modifié.", (array) $before, $data);
        return ['message' => 'Destinataire enregistré.'];
    }

    public function toggleRecipient(int $id)
    {
        $this->needSuperAdmin();
        $r = $this->recipientOr404($id);
        DB::table('alert_recipients')->where('id', $id)->update(['is_active' => !$r->is_active, 'updated_at' => now()]);
        Ctx::auditChange($r->is_active ? 'DESACTIVATION' : 'ACTIVATION', 'AlertRecipient', $id, "Destinataire {$r->email} " . ($r->is_active ? 'suspendu.' : 'réactivé.'), ['is_active' => (bool) $r->is_active], ['is_active' => !$r->is_active]);
        return ['message' => $r->is_active ? "{$r->email} ne reçoit plus d’alertes (suspendu)." : "{$r->email} reçoit de nouveau les alertes."];
    }

    public function destroyRecipient(int $id)
    {
        $this->needSuperAdmin();
        $r = $this->recipientOr404($id);
        DB::table('alert_recipients')->where('id', $id)->delete();
        Ctx::auditChange('SUPPRESSION', 'AlertRecipient', $id, "Destinataire d’alertes {$r->email} supprimé.", (array) $r, null);
        return ['message' => "{$r->email} supprimé de la liste des destinataires."];
    }

    /** Séquence d'envoi : activation, relance tous les N jours, nombre maximal d'envois, par catégorie. */
    public function updateRules(Request $r)
    {
        $this->needSuperAdmin();
        $d = $r->validate([
            'rules' => 'required|array',
            'rules.*.category' => ['required', Rule::in(array_keys(AlertEngine::CATEGORIES))],
            'rules.*.is_enabled' => 'required|boolean',
            'rules.*.interval_days' => 'required|integer|min:1|max:365',
            'rules.*.max_sends' => 'required|integer|min:1|max:50',
        ], [], ['rules.*.interval_days' => 'intervalle de relance', 'rules.*.max_sends' => 'nombre maximal d’envois']);
        $pid = Ctx::projectId();
        $before = DB::table('alert_rules')->where('project_id', $pid)->get()->map(fn ($x) => (array) $x)->all();
        foreach ($d['rules'] as $rule) {
            DB::table('alert_rules')->updateOrInsert(['project_id' => $pid, 'category' => $rule['category']],
                ['is_enabled' => $rule['is_enabled'], 'interval_days' => $rule['interval_days'], 'max_sends' => $rule['max_sends'], 'updated_at' => now(), 'created_at' => now()]);
        }
        Ctx::auditChange('MODIFICATION', 'AlertRule', $pid, 'Séquence d’envoi des alertes modifiée.', ['rules' => $before], ['rules' => $d['rules']]);
        return ['message' => 'Séquence d’envoi enregistrée.'];
    }

    /** Simulation (rien n'est envoyé) ou envoi immédiat des alertes dues. */
    public function run(Request $r)
    {
        $this->needSuperAdmin();
        $dry = $r->boolean('dry_run');
        $plan = AlertEngine::run(Ctx::projectId(), $dry, 'MANUEL', (int) Ctx::user()->id);
        $alerts = array_sum(array_map(fn ($m) => count($m['alerts']), $plan));
        $failed = count(array_filter($plan, fn ($m) => $m['status'] === 'ECHEC'));
        $message = !$plan ? 'Aucune alerte due : rien à envoyer pour le moment.'
            : ($dry ? count($plan) . " message(s) seraient envoyés ({$alerts} alerte(s))." : count($plan) . " message(s) traité(s)" . ($failed ? ", dont {$failed} en échec : vérifiez les paramètres SMTP." : '.'));
        return ['message' => $message, 'plan' => array_map(fn ($m) => ['category' => $m['category'], 'subject' => $m['subject'], 'recipients' => $m['recipients'], 'status' => $m['status'], 'message' => $m['message'],
            'alerts' => array_map(fn ($a) => ['title' => $a['title'], 'text' => $a['text'], 'sendNo' => $a['sendNo']], $m['alerts'])], $plan)];
    }
}
