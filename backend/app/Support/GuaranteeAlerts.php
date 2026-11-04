<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Relances des garanties de bonne exécution adressées à l'entreprise titulaire du contrat (adresse e-mail de sa fiche).
 *
 * La séquence suit la part consommée de la période de garantie en cours (la dernière prolongation ou le dernier
 * renouvellement, sinon la garantie initiale, qui couvre la durée du contrat) :
 *  - à partir de 75 % : alerte de vigilance, 1 envoi par semaine ; 85 % : 2 par semaine ; 95 % : 3 par semaine ;
 *  - échéance dépassée (plus de 100 %) : alerte de retard, 4 envois par semaine.
 * Les paliers sont réglés par l'administrateur (alert_rules.levels, catégorie GARANTIE) ; une garantie peut être
 * suspendue ou recevoir sa propre fréquence. Une prolongation, un renouvellement ou une modification des dates ouvre une
 * nouvelle période : la séquence en cours s'arrête (la clé d'envoi change) et ne reprend que si la nouvelle période
 * atteint à son tour le premier palier.
 *
 * Les fonctions level(), isDue() et nextDate() sont pures (testées dans tests/Unit/GuaranteeAlertsTest).
 */
class GuaranteeAlerts
{
    public const VIGILANCE = 'VIGILANCE';
    public const RETARD = 'RETARD';

    public const DEFAULT_LEVELS = ['vigilance' => [['from' => 75, 'perWeek' => 1], ['from' => 85, 'perWeek' => 2], ['from' => 95, 'perWeek' => 3]], 'retard' => 4];

    /** Jours d'envoi (ISO : 1 = lundi) selon le nombre d'envois par semaine. */
    public const WEEK_PLAN = [1 => [1], 2 => [1, 4], 3 => [1, 3, 5], 4 => [1, 2, 4, 5], 5 => [1, 2, 3, 4, 5], 6 => [1, 2, 3, 4, 5, 6], 7 => [1, 2, 3, 4, 5, 6, 7]];

    /** Paliers enregistrés, complétés et triés ; les paliers par défaut si rien n'est réglé. */
    public static function normalizeLevels($raw): array
    {
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($raw) || empty($raw['vigilance'])) return self::DEFAULT_LEVELS;
        $vig = [];
        foreach ($raw['vigilance'] as $l) {
            $vig[] = ['from' => max(1, min(100, (float) ($l['from'] ?? 0))), 'perWeek' => max(1, min(7, (int) ($l['perWeek'] ?? 1)))];
        }
        usort($vig, fn ($a, $b) => $a['from'] <=> $b['from']);
        return ['vigilance' => $vig, 'retard' => max(1, min(7, (int) ($raw['retard'] ?? self::DEFAULT_LEVELS['retard'])))];
    }

    /** Période de garantie en cours : la prolongation ou le renouvellement qui va le plus loin, sinon la garantie initiale. */
    public static function coverage(array $g): array
    {
        $best = null;
        foreach ($g['extensions'] ?? [] as $e) if ($best === null || $e['end'] > $best['end']) $best = $e;
        return $best ? ['start' => $best['start'], 'end' => $best['end'], 'type' => $best['type'] ?? 'PROLONGATION'] : ['start' => $g['start'], 'end' => $g['end'], 'type' => null];
    }

    /** Part consommée (en %, non plafonnée) de la période [start, end] au jour donné. */
    public static function consumed(string $start, string $end, string $today): float
    {
        if ($today < $start) return 0.0;
        $total = Dates::daysBetween($start, $end);
        if ($total <= 0) return $today > $end ? 100.0 : 0.0;
        return Dates::daysBetween($start, $today) / $total * 100;
    }

    /**
     * Palier atteint : null sous le premier seuil ; sinon [kind, perWeek, from, consumed].
     * L'alerte de retard part dès le lendemain de l'échéance.
     */
    public static function level(string $start, string $end, string $today, array $levels): ?array
    {
        $consumed = self::consumed($start, $end, $today);
        if ($today > $end) return ['kind' => self::RETARD, 'perWeek' => $levels['retard'], 'from' => 100, 'consumed' => $consumed];
        $hit = null;
        foreach ($levels['vigilance'] as $l) if ($consumed >= $l['from']) $hit = $l;
        return $hit ? ['kind' => self::VIGILANCE, 'perWeek' => $hit['perWeek'], 'from' => $hit['from'], 'consumed' => $consumed] : null;
    }

    private static function weekday(string $ymd): int
    {
        return (int) gmdate('N', gmmktime(0, 0, 0, (int) substr($ymd, 5, 2), (int) substr($ymd, 8, 2), (int) substr($ymd, 0, 4)));
    }

    /**
     * Un envoi est-il dû aujourd'hui ? $sent : dates (Y-m-d) des envois réussis de la période en cours, tous niveaux
     * confondus ; $kindStarted : un envoi de ce niveau (vigilance ou retard) a déjà eu lieu.
     * Jamais deux envois le même jour ni plus de $perWeek dans la semaine (lundi → dimanche). Le premier envoi d'un
     * niveau part tout de suite ; les suivants aux jours prévus (WEEK_PLAN), avec rattrapage d'un jour manqué.
     */
    public static function isDue(int $perWeek, array $sent, bool $kindStarted, string $today): bool
    {
        if ($perWeek < 1 || in_array($today, $sent, true)) return false;
        $wd = self::weekday($today);
        $monday = Dates::addDays($today, 1 - $wd);
        $thisWeek = count(array_filter($sent, fn ($d) => $d >= $monday && $d <= $today));
        if ($thisWeek >= $perWeek) return false;
        if (!$kindStarted) return true;
        $planned = count(array_filter(self::WEEK_PLAN[min(7, $perWeek)], fn ($d) => $d <= $wd));
        return $thisWeek < $planned;
    }

    /** Prochaine date d'envoi à partir d'aujourd'hui, en supposant le palier inchangé (null au-delà de 14 jours). */
    public static function nextDate(int $perWeek, array $sent, bool $kindStarted, string $today): ?string
    {
        for ($i = 0; $i <= 14; $i++) {
            $d = Dates::addDays($today, $i);
            if (self::isDue($perWeek, $sent, $kindStarted, $d)) return $d;
        }
        return null;
    }

    /**
     * Objet et texte du message (VIGILANCE, RETARD ou SATISFACTION), d'après le modèle du projet ($template) ou, à
     * défaut, le modèle par défaut (AlertTemplates).
     */
    public static function message(string $kind, array $vars, ?array $template = null): array
    {
        return AlertTemplates::render($template ?? AlertTemplates::DEFAULTS["GARANTIE_{$kind}"], $vars);
    }

    /** Intitulé du projet : code suivi du numéro de la phase en cours en chiffres romains à partir de la 2e (« PRISE II »). */
    public static function projectLabel(int $projectId): string
    {
        $code = (string) DB::table('projects')->where('id', $projectId)->value('code');
        $n = (int) DB::table('project_phases')->where('project_id', $projectId)->where('is_current', true)->value('number');
        if ($n <= 1) return $code;
        $out = '';
        foreach ([10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'] as $v => $s) while ($n >= $v) { $out .= $s; $n -= $v; }
        return "{$code} {$out}";
    }

    public static function levels(int $projectId): array
    {
        return self::normalizeLevels(DB::table('alert_rules')->where('project_id', $projectId)->where('category', 'GARANTIE')->value('levels'));
    }

    public static function enabled(int $projectId): bool
    {
        $v = DB::table('alert_rules')->where('project_id', $projectId)->where('category', 'GARANTIE')->value('is_enabled');
        return $v === null || (bool) $v;
    }

    /**
     * Situation des relances de chaque garantie de la phase en cours. Chaque ligne indique l'état de la séquence
     * (state : AUCUNE, EN_COURS, SUSPENDUE, SANS_ADRESSE, RESILIE, DESACTIVEE), si un envoi est dû aujourd'hui, la
     * prochaine date d'envoi et le message qui partirait.
     */
    public static function plan(int $projectId, ?string $today = null): array
    {
        $today ??= Dates::today();
        $phaseId = (int) DB::table('project_phases')->where('project_id', $projectId)->where('is_current', true)->value('id');
        $levels = self::levels($projectId);
        $enabled = self::enabled($projectId);
        $label = self::projectLabel($projectId);
        $rows = Loader::guaranteeRows(['project_id' => $projectId, 'phase_id' => $phaseId]);
        if (!$rows) return [];
        $extra = DB::table('performance_guarantees as g')->join('contracts as c', 'c.id', '=', 'g.contract_id')
            ->leftJoin('companies as co', 'co.id', '=', 'c.company_id')->leftJoin('users as u', 'u.id', '=', 'g.alerts_paused_by')
            ->whereIn('g.id', array_column($rows, 'id'))
            ->get(['g.id', 'co.email', 'c.termination_date', 'g.alerts_paused_at', 'g.alerts_pause_reason', 'g.alerts_per_week', 'u.full_name as paused_by'])->keyBy('id');
        $history = [];
        foreach (DB::table('alert_dispatches')->where('project_id', $projectId)->where('category', 'GARANTIE')->where('status', 'ENVOYE')
            ->where('alert_key', 'like', 'GARANTIE:%:%')->orderBy('id')->get(['alert_key', 'created_at']) as $h) {
            $history[$h->alert_key][] = Dates::part($h->created_at);
        }

        $templates = AlertTemplates::all($projectId);
        $out = [];
        foreach ($rows as $g) {
            $x = $extra[$g['id']] ?? null;
            $cov = self::coverage($g);
            $prefix = "GARANTIE:{$g['id']}:{$cov['start']}:{$cov['end']}:";
            // Contrat renouvelé : la garantie n'est plus exigée, plus aucune relance.
            $lvl = empty($g['contract_renewed']) ? self::level($cov['start'], $cov['end'], $today, $levels) : null;
            $override = $x && $x->alerts_per_week ? (int) $x->alerts_per_week : null;
            $perWeek = $lvl ? ($override ?? $lvl['perWeek']) : 0;
            $kindCode = $lvl ? substr($lvl['kind'], 0, 1) : '';
            $sent = array_merge($history[$prefix . 'V'] ?? [], $history[$prefix . 'R'] ?? []);
            sort($sent);
            $kindStarted = $lvl && !empty($history[$prefix . $kindCode]);
            $email = trim((string) ($x->email ?? ''));
            $blocked = match (true) {
                $x && $x->termination_date !== null => 'RESILIE',
                !$enabled => 'DESACTIVEE',
                $x && $x->alerts_paused_at !== null => 'SUSPENDUE',
                !filter_var($email, FILTER_VALIDATE_EMAIL) => 'SANS_ADRESSE',
                default => null,
            };
            $state = !empty($g['contract_renewed']) ? 'RENOUVELE' : ($blocked === 'RESILIE' ? 'RESILIE' : ($lvl === null ? 'AUCUNE' : ($blocked ?? 'EN_COURS')));
            $active = $state === 'EN_COURS';
            $vars = [
                'contrat' => $g['contract_number'], 'numero_garantie' => $g['account'], 'entreprise' => $g['company'], 'banque' => $g['bank'],
                'montant_garanti' => number_format($g['amount'], 2, ',', ' '), 'debut' => Dates::fr($cov['start']), 'echeance' => Dates::fr($cov['end']),
                'jours_consommes' => number_format(self::consumed($cov['start'], $cov['end'], $today), 1, ',', ' ') . ' %',
                'nature' => $cov['type'] === 'RENOUVELLEMENT' ? 'le renouvellement' : 'la prolongation', 'projet' => $label,
            ];
            $msg = $lvl ? self::message($lvl['kind'], $vars, $templates["GARANTIE_{$lvl['kind']}"]) : null;

            // Satisfaction : une fois, après la prolongation ou le renouvellement d'une garantie qui avait été relancée.
            $chased = false;
            foreach ($history as $key => $dates) {
                if (str_starts_with($key, "GARANTIE:{$g['id']}:") && !str_starts_with($key, $prefix) && !str_ends_with($key, ':S')) { $chased = true; break; }
            }
            $satisfactionDue = empty($g['contract_renewed']) && $cov['type'] !== null && $chased && empty($history[$prefix . 'S']) && ($blocked === null || $blocked === 'SANS_ADRESSE');
            $thanks = $satisfactionDue ? self::message('SATISFACTION', $vars, $templates['GARANTIE_SATISFACTION']) : null;

            $out[] = [
                'id' => $g['id'], 'contractId' => $g['contract_id'], 'contractNumber' => $g['contract_number'], 'guaranteeNumber' => $g['account'], 'company' => $g['company'], 'email' => $email,
                'periodStart' => $cov['start'], 'periodEnd' => $cov['end'], 'consumed' => round(self::consumed($cov['start'], $cov['end'], $today), 1),
                'kind' => $lvl['kind'] ?? null, 'threshold' => $lvl['from'] ?? null, 'autoPerWeek' => $lvl['perWeek'] ?? null, 'perWeekOverride' => $override, 'perWeek' => $perWeek,
                'state' => $state, 'sentCount' => count($sent), 'lastSent' => $sent ? end($sent) : null,
                'due' => $active && self::isDue($perWeek, $sent, $kindStarted, $today),
                'nextDate' => $active ? self::nextDate($perWeek, $sent, $kindStarted, $today) : null,
                'pausedAt' => $x && $x->alerts_paused_at ? substr((string) $x->alerts_paused_at, 0, 16) : null, 'pausedBy' => $x->paused_by ?? null, 'pauseReason' => $x->alerts_pause_reason ?? null,
                'key' => $lvl ? $prefix . $kindCode : null, 'prefix' => $prefix, 'sendNo' => count($sent) + 1,
                'subject' => $msg['subject'] ?? null, 'text' => $msg['text'] ?? null, 'vars' => $vars,
                'satisfactionDue' => $satisfactionDue, 'satisfactionSubject' => $thanks['subject'] ?? null, 'satisfactionText' => $thanks['text'] ?? null,
            ];
        }
        return $out;
    }
}
