<?php

namespace App\Support;

use App\Http\Controllers\FleetController;
use Illuminate\Support\Facades\DB;

/**
 * Moteur d'alertes par e-mail (TDR §10). Pour le stock et le charroi : séquence de chaque catégorie (relance
 * tous les N jours, nombre maximal d'envois), un message par catégorie et par groupe de destinataires. Pour les garanties
 * et les contrats : relances à l'entreprise (GuaranteeAlerts, runContracts) et rappel hebdomadaire distinct au personnel du projet.
 * Pour les décomptes en attente : rappel quotidien au compte habilité à les valider.
 * Utilisé par la commande planifiée prise:send-alerts et par l'écran du super-admin (simulation, envoi immédiat).
 * Fonctionne sans requête HTTP : tout est lu directement en base.
 */
class AlertEngine
{
    public const CATEGORIES = [
        'RETARD' => 'Contrats (vigilance et retard)', 'GARANTIE' => 'Garanties de bonne exécution', 'DECOMPTE' => 'Décomptes à valider',
        'STOCK' => 'Stock (rupture, niveau minimum)', 'CHARROI' => 'Charroi (réservoir, entretien, assurance, carnet)', 'GROUPE' => 'Groupes électrogènes',
    ];

    /** Alertes en cours : [category, key, unitId, title, text]. */
    public static function collect(int $projectId): array
    {
        $today = Dates::today();
        $out = [];
        // Les garanties, les contrats et les décomptes suivent leurs propres séquences : voir run().
        // Stock : articles en rupture ou au niveau minimum, unité par unité.
        $stocks = DB::table('stock_movements as m')->join('stock_articles as a', 'a.id', '=', 'm.stock_article_id')->join('organizational_units as u', 'u.id', '=', 'm.organizational_unit_id')
            ->where('m.project_id', $projectId)->whereNull('m.cancelled_at')->where('a.is_active', true)
            ->groupBy('m.organizational_unit_id', 'u.name', 'a.id', 'a.designation', 'a.unit', 'a.alert_threshold')
            ->selectRaw("m.organizational_unit_id AS unit_id, u.name AS unit_name, a.id, a.designation, a.unit, a.alert_threshold, SUM(CASE WHEN m.type = 'ENTREE' THEN m.quantity ELSE -m.quantity END) AS stock")->get();
        foreach ($stocks as $s) {
            $qty = round((float) $s->stock, 2);
            $threshold = StockLedger::threshold((string) $s->unit, $s->alert_threshold === null ? null : (float) $s->alert_threshold);
            $low = $threshold !== null && $qty <= $threshold;
            if ($qty <= 0 || $low) {
                $out[] = ['category' => 'STOCK', 'key' => "STOCK:{$s->unit_id}:{$s->id}", 'unitId' => (int) $s->unit_id, 'title' => "{$s->designation} ({$s->unit_name})",
                    'level' => $qty <= 0 ? 'danger' : 'warning', 'code' => $qty <= 0 ? 'OUT' : 'LOW', 'itemId' => (int) $s->id, 'name' => $s->designation, 'unitName' => $s->unit_name,
                    'text' => $qty <= 0 ? 'en rupture de stock.' : "plus que {$qty} {$s->unit} (niveau minimum {$threshold})."];
            }
        }
        // Charroi et groupes : mêmes alertes que l'écran « Véhicules & alertes ».
        $assets = DB::table('fleet_assets as a')->join('organizational_units as u', 'u.id', '=', 'a.organizational_unit_id')->where('a.project_id', $projectId)->where('a.is_active', true)->select('a.*', 'u.name as unit_name')->get();
        $logs = DB::table('fleet_logs')->where('project_id', $projectId)->get()->groupBy('fleet_asset_id');
        $maint = DB::table('fleet_maintenances')->where('project_id', $projectId)->orderBy('maintenance_date')->get()->groupBy('fleet_asset_id');
        $ins = DB::table('fleet_insurances')->where('project_id', $projectId)->get()->groupBy('fleet_asset_id');
        foreach ($assets as $a) {
            $asset = FleetController::assetRow($a);
            $status = FleetController::status($asset, ($logs[$a->id] ?? collect())->map(fn ($l) => FleetController::logRow($l))->all(), ($maint[$a->id] ?? collect())->all(),
                ($ins[$a->id] ?? collect())->map(fn ($i) => ['start_date' => Dates::part($i->start_date), 'end_date' => Dates::part($i->end_date), 'designation' => $i->designation])->all(), $today);
            $cat = $a->kind === FleetMetrics::GROUPE ? 'GROUPE' : 'CHARROI';
            foreach ($status['alerts'] as $al) {
                $out[] = ['category' => $cat, 'key' => "{$cat}:{$a->id}:{$al['code']}", 'unitId' => (int) $a->organizational_unit_id,
                    'level' => $al['level'], 'code' => $al['code'], 'itemId' => (int) $a->id, 'name' => trim("{$a->name} {$a->registration}"), 'unitName' => $a->unit_name,
                    'title' => trim("{$a->name} {$a->registration}") . " ({$a->unit_name})", 'text' => $al['text']];
            }
        }
        return $out;
    }

    /**
     * Alertes logistiques en cours (stock, charroi, groupes électrogènes) dans le périmètre lu ($unitIds null : tout le
     * projet), les plus graves d'abord.
     */
    public static function logistics(int $projectId, ?array $unitIds): array
    {
        $out = array_values(array_filter(self::collect($projectId), fn ($a) => in_array($a['category'], ['STOCK', 'CHARROI', 'GROUPE'], true) && ($unitIds === null || in_array($a['unitId'], $unitIds, true))));
        usort($out, fn ($a, $b) => [$a['level'] === 'danger' ? 0 : 1, $a['category'], $a['name']] <=> [$b['level'] === 'danger' ? 0 : 1, $b['category'], $b['name']]);
        return $out;
    }

    /** Destinataires actifs d'une catégorie ; pour une alerte d'unité, ceux de cette unité et ceux de toutes les unités. */
    public static function recipients(int $projectId, string $category, ?int $unitId): array
    {
        return DB::table('alert_recipients')->where('project_id', $projectId)->where('is_active', true)
            ->whereRaw('FIND_IN_SET(?, categories)', [$category])
            ->when($unitId, fn ($q) => $q->where(fn ($w) => $w->whereNull('organizational_unit_id')->orWhere('organizational_unit_id', $unitId)))
            ->orderBy('email')->pluck('email')->all();
    }

    /** Une alerte est-elle due ? Jamais envoyée, ou dernière relance il y a au moins interval_days, sans dépasser max_sends. */
    public static function isDue(int $sent, ?string $lastSentDate, int $intervalDays, int $maxSends, string $today): bool
    {
        if ($sent >= $maxSends) return false;
        return $lastSentDate === null || Dates::daysBetween($lastSentDate, $today) >= $intervalDays;
    }

    /**
     * Plan d'envoi (et envoi si $dryRun est faux) : un message par catégorie et par groupe de destinataires.
     * Retourne [['category', 'subject', 'recipients'[], 'alerts'[], 'status', 'message']].
     */
    public static function run(int $projectId, bool $dryRun, string $trigger = 'AUTO', ?int $userId = null): array
    {
        $today = Dates::today();
        $rules = DB::table('alert_rules')->where('project_id', $projectId)->get()->keyBy('category');
        $history = DB::table('alert_dispatches')->where('project_id', $projectId)->where('status', 'ENVOYE')
            ->groupBy('alert_key')->selectRaw('alert_key, COUNT(*) AS n, MAX(created_at) AS last')->get()->keyBy('alert_key');
        $project = DB::table('projects')->where('id', $projectId)->value('code');

        $groups = [];
        foreach (self::collect($projectId) as $al) {
            // Séquence par défaut (relance tous les 7 jours, 3 envois) si le super-admin ne l'a pas encore réglée.
            $rule = $rules[$al['category']] ?? (object) ['is_enabled' => true, 'interval_days' => 7, 'max_sends' => 3];
            if (!$rule->is_enabled) continue;
            $h = $history[$al['key']] ?? null;
            if (!self::isDue((int) ($h->n ?? 0), $h ? Dates::part($h->last) : null, (int) $rule->interval_days, (int) $rule->max_sends, $today)) continue;
            $to = self::recipients($projectId, $al['category'], $al['unitId']);
            if (!$to) continue;
            $gk = $al['category'] . '|' . implode(',', $to);
            $groups[$gk] ??= ['category' => $al['category'], 'recipients' => $to, 'alerts' => []];
            $groups[$gk]['alerts'][] = $al + ['sendNo' => (int) ($h->n ?? 0) + 1];
        }

        $plan = [];
        foreach ($groups as $g) {
            $n = count($g['alerts']);
            $subject = "[{$project}] " . self::CATEGORIES[$g['category']] . " : {$n} alerte(s)";
            $lines = array_map(fn ($a) => "• {$a['title']} : {$a['text']}" . ($a['sendNo'] > 1 ? " (relance n° {$a['sendNo']})" : ''), $g['alerts']);
            $text = "Alertes du " . Dates::fr($today) . " — " . self::CATEGORIES[$g['category']] . "\n\n" . implode("\n", $lines)
                . "\n\nMessage automatique de l’application de suivi {$project}. Les destinataires et la séquence d’envoi sont réglés par le super-administrateur.";
            $entry = ['category' => $g['category'], 'subject' => $subject, 'recipients' => $g['recipients'], 'alerts' => $g['alerts'], 'status' => 'SIMULATION', 'message' => ''];
            if (!$dryRun) {
                // Synthèse rédigée par l'IA en tête du message, si le super-admin l'a autorisée ; sans elle, le message part tel quel.
                if (AiService::enabled($projectId, 'alerts')) {
                    $ai = AiService::complete($projectId, 'Tu rédiges en français, en 2 à 4 phrases, la synthèse d’un message d’alerte destiné à l’équipe d’un projet : '
                        . 'ce qui est le plus urgent, puis l’action attendue. Uniquement à partir des alertes fournies, sans formule de politesse.', implode("\n", $lines), 600);
                    if ($ai['ok']) $text = "Synthèse (rédigée par l’IA) : {$ai['text']}\n\n" . $text;
                }
                $res = MailService::send($projectId, $subject, $text, $g['recipients']);
                $entry['status'] = $res['success'] ? 'ENVOYE' : 'ECHEC';
                $entry['message'] = $res['message'];
                foreach ($g['alerts'] as $a) {
                    DB::table('alert_dispatches')->insert([
                        'project_id' => $projectId, 'category' => $g['category'], 'alert_key' => $a['key'], 'subject' => mb_substr("{$a['title']} : {$a['text']}", 0, 255),
                        'recipients' => implode(', ', $g['recipients']), 'status' => $entry['status'], 'message' => mb_substr($res['message'], 0, 1000),
                        'trigger' => $trigger, 'sent_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                Ctx::audit('ALERTE_' . $g['category'], 'AlertDispatch', $g['category'], "{$subject} — " . ($res['success'] ? 'envoyé' : 'échec') . ' (' . ($trigger === 'AUTO' ? 'envoi planifié' : 'envoi manuel') . "). {$res['message']}", $projectId, $userId);
            }
            $plan[] = $entry;
        }
        $guarantees = GuaranteeAlerts::plan($projectId);
        return array_merge($plan, self::runGuarantees($guarantees, $projectId, $dryRun, $trigger, $userId), self::runContracts($rules, $history, $projectId, $dryRun, $trigger, $userId), self::runStaff($guarantees, $rules, $history, $projectId, $dryRun, $trigger, $userId),
            self::runDecompteReminders($rules, $history, $projectId, $dryRun, $trigger, $userId));
    }

    /**
     * Rappel d'un décompte à valider : un jour après sa transmission puis chaque jour tant qu'il est en attente, à l'adresse
     * du compte qui détient explicitement can_validate_decomptes (le chef de cellule infrastructures). Jamais au
     * super-admin ni à la personne qui a établi le décompte. Sans valideur désigné, rien ne part : le plan le signale.
     */
    private static function runDecompteReminders($rules, $history, int $projectId, bool $dryRun, string $trigger, ?int $userId): array
    {
        if (!(bool) ($rules['DECOMPTE']->is_enabled ?? true)) return [];
        $today = Dates::today();
        $lines = DB::table('contract_decomptes as d')->join('contracts as c', 'c.id', '=', 'd.contract_id')->leftJoin('companies as co', 'co.id', '=', 'c.company_id')->leftJoin('users as u', 'u.id', '=', 'd.submitted_by')
            ->where('d.project_id', $projectId)->where('d.status', DecompteLedger::PENDING)->where('d.created_at', '<=', now()->subDay())->orderBy('d.created_at')
            ->get(['d.id', 'd.number', 'd.gross_amount', 'd.operation_date', 'd.created_at', 'd.submitted_by', 'd.contract_id', 'c.reference', 'co.name as company', 'u.full_name as submitted_by_name']);
        if ($lines->isEmpty()) return [];
        $validators = DB::table('users')->where('project_id', $projectId)->where('is_active', true)->where('is_pending', false)->where('is_super_admin', false)
            ->where('can_validate_decomptes', true)->get(['id', 'email']);
        $template = AlertTemplates::all($projectId)['DECOMPTE_RAPPEL'];
        $label = GuaranteeAlerts::projectLabel($projectId);

        $plan = [];
        foreach ($lines as $d) {
            $key = "DECOMPTE_RAPPEL:{$d->id}";
            $h = $history[$key] ?? null;
            if (!self::isDue((int) ($h->n ?? 0), $h ? Dates::part($h->last) : null, 1, PHP_INT_MAX, $today)) continue;
            $to = $validators->filter(fn ($v) => (int) $v->id !== (int) $d->submitted_by && filter_var($v->email, FILTER_VALIDATE_EMAIL))->pluck('email')->values()->all();
            $sentOn = Dates::part($d->created_at);
            $days = max(1, Dates::daysBetween($sentOn, $today));
            $msg = AlertTemplates::render($template, [
                'decompte' => $d->number, 'contrat' => $d->reference, 'entreprise' => $d->company, 'montant' => number_format((float) $d->gross_amount, 2, ',', ' '),
                'date_decompte' => Dates::fr(Dates::part($d->operation_date)), 'transmis_le' => Dates::fr($sentOn), 'saisi_par' => $d->submitted_by_name, 'attente' => $days . ($days > 1 ? ' jours' : ' jour'),
                'lien' => config('prise.frontend_url') . '/decomptes?contrat=' . $d->contract_id, 'projet' => $label,
            ]);
            $entry = ['category' => 'DECOMPTE', 'subject' => $msg['subject'], 'recipients' => $to, 'status' => 'SIMULATION', 'message' => '',
                'alerts' => [['key' => $key, 'title' => "{$d->number} du contrat {$d->reference}", 'text' => "en attente de décision depuis le " . Dates::fr($sentOn) . '.', 'sendNo' => (int) ($h->n ?? 0) + 1]]];
            if (!$to) {
                $entry['status'] = 'SANS_DESTINATAIRE';
                $entry['message'] = 'Aucun compte n’est habilité à valider ce décompte : le super-administrateur doit l’accorder au chef de cellule infrastructures dans Utilisateurs.';
                $plan[] = $entry;
                continue;
            }
            if (!$dryRun) {
                $res = MailService::send($projectId, $msg['subject'], $msg['text'], $to);
                $entry['status'] = $res['success'] ? 'ENVOYE' : 'ECHEC';
                $entry['message'] = $res['message'];
                self::record($projectId, ['category' => 'DECOMPTE', 'key' => $key, 'subject' => $msg['subject'], 'recipients' => $to, 'status' => $entry['status'], 'message' => $res['message'],
                    'trigger' => $trigger, 'userId' => $userId, 'contractId' => (int) $d->contract_id, 'type' => 'RAPPEL', 'body' => $msg['text']]);
                Ctx::audit('ALERTE_DECOMPTE', 'Decompte', $d->id, "{$msg['subject']} — rappel n° " . ((int) ($h->n ?? 0) + 1) . ' : ' . ($res['success'] ? 'envoyé' : 'échec') . ' (' . ($trigger === 'AUTO' ? 'envoi planifié' : 'envoi manuel') . "). {$res['message']}", $projectId, $userId);
            }
            $plan[] = $entry;
        }
        return $plan;
    }

    /** Valeurs des champs des modèles d'un contrat ; niveau : « vigilance » ou « retard » pour le personnel du projet. */
    public static function contractVars(array $c, int $projectId): array
    {
        $status = Metrics::status($c);
        return [
            'contrat' => $c['number'], 'entreprise' => $c['company'], 'intitule' => $c['title'], 'avancement' => number_format(Metrics::pct(Metrics::progress($c)), 1, ',', ' ') . ' %',
            'jours_consommes' => number_format(Metrics::pct(Metrics::elapsed($c, Dates::today())), 1, ',', ' ') . ' %', 'date_debut' => Dates::fr($c['start_date']),
            'date_fin' => Dates::fr($c['end_date']), 'projet' => GuaranteeAlerts::projectLabel($projectId), 'niveau' => mb_strtolower($status),
        ];
    }

    /**
     * Alertes des contrats à l'entreprise, à la seule adresse de sa fiche : vigilance et retard une fois par semaine tant que
     * le contrat reste dans cet état, message de satisfaction une seule fois quand il est terminé. Un envoi manuel depuis la
     * page Alertes compte dans la séquence (même clé). La catégorie RETARD désactivée coupe aussi ces envois.
     */
    private static function runContracts($rules, $history, int $projectId, bool $dryRun, string $trigger, ?int $userId): array
    {
        if (!(bool) ($rules['RETARD']->is_enabled ?? true)) return [];
        $today = Dates::today();
        $kinds = ['Vigilance' => 'VIGILANCE', 'Retard' => 'RETARD', 'Terminé' => 'SATISFACTION'];
        $phaseId = (int) DB::table('project_phases')->where('project_id', $projectId)->where('is_current', true)->value('id');
        $contracts = Loader::contracts($projectId, $phaseId);
        $emails = DB::table('companies')->whereIn('id', array_column($contracts, 'company_id') ?: [0])->pluck('email', 'id');
        $templates = AlertTemplates::all($projectId);
        $plan = [];
        foreach ($contracts as $c) {
            $kind = $kinds[Metrics::status($c)] ?? null;
            if (!$kind || $c['terminated']) continue;
            $key = "CONTRAT:{$c['id']}:{$kind}";
            $h = $history[$key] ?? null;
            $sent = (int) ($h->n ?? 0);
            if (!self::isDue($sent, $h ? Dates::part($h->last) : null, 7, $kind === 'SATISFACTION' ? 1 : PHP_INT_MAX, $today)) continue;
            $vars = self::contractVars($c, $projectId);
            $msg = AlertTemplates::render($templates["CONTRAT_{$kind}"], $vars);
            $email = trim((string) ($emails[$c['company_id']] ?? ''));
            $valid = (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
            $entry = ['category' => 'CONTRAT', 'subject' => $msg['subject'], 'recipients' => $valid ? [$email] : [], 'status' => 'SIMULATION', 'message' => '',
                'alerts' => [['key' => $key, 'title' => "Contrat {$c['number']} — {$c['company']}", 'text' => "avancement {$vars['avancement']} pour {$vars['jours_consommes']} du délai consommé.", 'sendNo' => $sent + 1]]];
            if (!$valid) {
                // Rien ne part tant que la fiche de l'entreprise n'a pas d'adresse valide ; la séquence n'est pas consommée.
                $entry['status'] = 'SANS_ADRESSE';
                $entry['message'] = "L’entreprise {$c['company']} n’a pas d’adresse e-mail valide : complétez sa fiche dans Entreprises.";
                $plan[] = $entry;
                continue;
            }
            if (!$dryRun) {
                $res = MailService::send($projectId, $msg['subject'], $msg['text'], [$email]);
                $entry['status'] = $res['success'] ? 'ENVOYE' : 'ECHEC';
                $entry['message'] = $res['message'];
                self::record($projectId, ['category' => 'CONTRAT', 'key' => $key, 'subject' => $msg['subject'], 'recipients' => [$email], 'status' => $entry['status'], 'message' => $res['message'],
                    'trigger' => $trigger, 'userId' => $userId, 'contractId' => $c['id'], 'type' => $kind, 'body' => $msg['text']]);
                Ctx::audit($kind === 'SATISFACTION' ? 'ALERTE_SATISFACTION_CONTRAT' : "ALERTE_{$kind}", 'Contract', $c['id'], "{$msg['subject']} à {$email} : " . ($res['success'] ? 'envoyé' : 'échec')
                    . ' (' . ($trigger === 'AUTO' ? 'envoi planifié' : 'envoi manuel') . "). {$res['message']}", $projectId, $userId);
            }
            $plan[] = $entry;
        }
        return $plan;
    }

    /**
     * Rappel au personnel du projet, distinct du message de l'entreprise : un message par garantie ou par contrat à
     * suivre, une fois par semaine et sans limite de nombre. Il s'arrête à la prolongation ou au renouvellement de la
     * garantie (la clé contient sa période et le palier retombe) et, pour un contrat, dès qu'il n'est plus en vigilance
     * ni en retard. L'interruption des relances d'une garantie ne vise que l'entreprise : le personnel reste informé.
     */
    private static function runStaff(array $guarantees, $rules, $history, int $projectId, bool $dryRun, string $trigger, ?int $userId): array
    {
        $today = Dates::today();
        $templates = AlertTemplates::all($projectId);
        $items = [];
        if (GuaranteeAlerts::enabled($projectId)) {
            foreach ($guarantees as $g) {
                if (!$g['kind'] || $g['state'] === 'RESILIE') continue;
                $items[] = ['category' => 'GARANTIE', 'key' => "PERSONNEL:GARANTIE:{$g['id']}:{$g['periodStart']}:{$g['periodEnd']}", 'code' => 'GARANTIE_PERSONNEL',
                    'vars' => $g['vars'] + ['niveau' => mb_strtolower($g['kind'])], 'contractId' => $g['contractId'], 'guaranteeId' => $g['id'], 'guaranteeNumber' => $g['guaranteeNumber'],
                    'title' => 'Garantie n° ' . ($g['guaranteeNumber'] ?: '—') . " du contrat {$g['contractNumber']} — {$g['company']}", 'entity' => ['PerformanceGuarantee', $g['id']],
                    'text' => mb_strtolower($g['kind']) . ', échéance le ' . Dates::fr($g['periodEnd']) . '.'];
            }
        }
        if ((bool) ($rules['RETARD']->is_enabled ?? true)) {
            $phaseId = (int) DB::table('project_phases')->where('project_id', $projectId)->where('is_current', true)->value('id');
            foreach (Loader::contracts($projectId, $phaseId) as $c) {
                $status = Metrics::status($c);
                if ($c['terminated'] || !in_array($status, ['Vigilance', 'Retard'], true)) continue;
                $vars = self::contractVars($c, $projectId);
                $items[] = ['category' => 'RETARD', 'key' => "PERSONNEL:CONTRAT:{$c['id']}", 'code' => 'CONTRAT_PERSONNEL', 'vars' => $vars, 'contractId' => $c['id'], 'guaranteeId' => null, 'guaranteeNumber' => null,
                    'title' => "Contrat {$c['number']} — {$c['company']}", 'entity' => ['Contract', $c['id']],
                    'text' => "{$vars['niveau']}, avancement {$vars['avancement']} pour {$vars['jours_consommes']} du délai consommé."];
            }
        }

        $plan = [];
        foreach ($items as $it) {
            $h = $history[$it['key']] ?? null;
            if (!self::isDue((int) ($h->n ?? 0), $h ? Dates::part($h->last) : null, 7, PHP_INT_MAX, $today)) continue;
            $to = self::recipients($projectId, $it['category'], null);
            if (!$to) continue;
            $msg = AlertTemplates::render($templates[$it['code']], $it['vars']);
            $entry = ['category' => $it['category'], 'subject' => $msg['subject'], 'recipients' => $to, 'status' => 'SIMULATION', 'message' => '',
                'alerts' => [['key' => $it['key'], 'title' => "Personnel du projet — {$it['title']}", 'text' => $it['text'], 'sendNo' => (int) ($h->n ?? 0) + 1]]];
            if (!$dryRun) {
                $res = MailService::send($projectId, $msg['subject'], $msg['text'], $to);
                $entry['status'] = $res['success'] ? 'ENVOYE' : 'ECHEC';
                $entry['message'] = $res['message'];
                self::record($projectId, ['category' => $it['category'], 'key' => $it['key'], 'subject' => $msg['subject'], 'recipients' => $to, 'status' => $entry['status'], 'message' => $res['message'],
                    'trigger' => $trigger, 'userId' => $userId, 'contractId' => $it['contractId'], 'guaranteeId' => $it['guaranteeId'], 'guaranteeNumber' => $it['guaranteeNumber'], 'type' => 'PERSONNEL', 'body' => $msg['text']]);
                Ctx::audit('ALERTE_PERSONNEL', $it['entity'][0], $it['entity'][1], "{$msg['subject']} — rappel au personnel du projet : " . ($res['success'] ? 'envoyé' : 'échec')
                    . ' (' . ($trigger === 'AUTO' ? 'envoi planifié' : 'envoi manuel') . "). {$res['message']}", $projectId, $userId);
            }
            $plan[] = $entry;
        }
        return $plan;
    }

    /**
     * Trace un envoi (ou un échec) dans alert_dispatches, avec le contrat, la garantie, la nature et le texte du message,
     * pour l'historique des envois filtré par contrat ou par n° de garantie.
     */
    public static function record(int $projectId, array $d): void
    {
        DB::table('alert_dispatches')->insert([
            'project_id' => $projectId, 'category' => $d['category'], 'alert_key' => mb_substr($d['key'], 0, 190), 'subject' => mb_substr($d['subject'], 0, 255),
            'recipients' => implode(', ', $d['recipients']), 'status' => $d['status'], 'message' => mb_substr((string) $d['message'], 0, 1000),
            'trigger' => $d['trigger'], 'sent_by' => $d['userId'] ?? null, 'contract_id' => $d['contractId'] ?? null, 'performance_guarantee_id' => $d['guaranteeId'] ?? null,
            'guarantee_number' => isset($d['guaranteeNumber']) && $d['guaranteeNumber'] !== '' ? mb_substr($d['guaranteeNumber'], 0, 190) : null,
            'message_type' => $d['type'] ?? null, 'body' => $d['body'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Relances des garanties : un message par garantie, adressé à la seule entreprise du contrat (le personnel du projet
     * a son propre message : runStaff). Seuls les envois réussis consomment la séquence (GuaranteeAlerts).
     * Après une prolongation ou un renouvellement d'une garantie relancée, le message de satisfaction part une fois.
     */
    private static function runGuarantees(array $guarantees, int $projectId, bool $dryRun, string $trigger, ?int $userId): array
    {
        $plan = [];
        foreach ($guarantees as $g) {
            $messages = [];
            if ($g['due'] || $g['state'] === 'SANS_ADRESSE') {
                $retard = $g['kind'] === GuaranteeAlerts::RETARD;
                $messages[] = ['type' => $g['kind'], 'key' => $g['key'], 'subject' => $g['subject'], 'body' => $g['text'], 'sendNo' => $g['sendNo'],
                    'text' => ($retard ? 'alerte de retard, échue depuis le ' : 'alerte de vigilance, échéance le ') . Dates::fr($g['periodEnd'])
                        . ' (' . number_format($g['consumed'], 1, ',', ' ') . " % de la période consommés, {$g['perWeek']} envoi(s) par semaine)."];
            }
            if ($g['satisfactionDue']) {
                $messages[] = ['type' => 'SATISFACTION', 'key' => $g['prefix'] . 'S', 'subject' => $g['satisfactionSubject'], 'body' => $g['satisfactionText'], 'sendNo' => 1,
                    'text' => 'message de satisfaction : garantie régularisée jusqu’au ' . Dates::fr($g['periodEnd']) . '.'];
            }
            foreach ($messages as $m) {
                $alert =['key' => $m['key'], 'title' => "Garantie n° " . ($g['guaranteeNumber'] ?: '—') . " du contrat {$g['contractNumber']} — {$g['company']}", 'text' => $m['text'], 'sendNo' => $m['sendNo']];
                $entry = ['category' => 'GARANTIE', 'subject' => $m['subject'], 'recipients' => $g['email'] ? [$g['email']] : [],
                    'alerts' => [$alert], 'status' => 'SIMULATION', 'message' => ''];
                if ($g['state'] === 'SANS_ADRESSE' || !filter_var($g['email'], FILTER_VALIDATE_EMAIL)) {
                    // Rien ne part tant que la fiche de l'entreprise n'a pas d'adresse valide ; la séquence n'est pas consommée.
                    $entry['status'] = 'SANS_ADRESSE';
                    $entry['message'] = "L’entreprise {$g['company']} n’a pas d’adresse e-mail valide : complétez sa fiche dans Entreprises.";
                    $plan[] = $entry;
                    continue;
                }
                if (!$dryRun) {
                    $res = MailService::send($projectId, $m['subject'], $m['body'], [$g['email']]);
                    $entry['status'] = $res['success'] ? 'ENVOYE' : 'ECHEC';
                    $entry['message'] = $res['message'];
                    self::record($projectId, ['category' => 'GARANTIE', 'key' => $m['key'], 'subject' => $m['subject'], 'recipients' => $entry['recipients'], 'status' => $entry['status'],
                        'message' => $res['message'], 'trigger' => $trigger, 'userId' => $userId, 'contractId' => $g['contractId'], 'guaranteeId' => $g['id'],
                        'guaranteeNumber' => $g['guaranteeNumber'], 'type' => $m['type'], 'body' => $m['body']]);
                    Ctx::audit('ALERTE_GARANTIE', 'PerformanceGuarantee', $g['id'], "{$m['subject']} — " . ($m['type'] === 'SATISFACTION' ? 'message de satisfaction' : "relance n° {$m['sendNo']}")
                        . " à {$g['email']} : " . ($res['success'] ? 'envoyé' : 'échec') . ' (' . ($trigger === 'AUTO' ? 'envoi planifié' : 'envoi manuel') . "). {$res['message']}", $projectId, $userId);
                }
                $plan[] = $entry;
            }
        }
        return $plan;
    }
}
