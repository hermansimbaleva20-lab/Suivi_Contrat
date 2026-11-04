<?php
namespace App\Http\Controllers;

use App\Support\AlertEngine;
use App\Support\Ctx;
use App\Support\GuaranteeAlerts;
use App\Support\Dates;
use App\Support\Loader;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuaranteeController extends Controller
{
    private function gOr404(int $id): object
    {
        $g = DB::table('performance_guarantees as g')->join('contracts as c', 'c.id', '=', 'g.contract_id')
            ->where('g.id', $id)->where('g.project_id', Ctx::projectId())->select('g.*', DB::raw('c.amount - c.renewed_amount as contract_amount'), 'c.reference as contract_number')->first();
        abort_unless($g, 404, 'Garantie introuvable.');
        return $g;
    }

    public function index()
    {
        $rows = array_map(fn ($g) => Views::guarantee($g), Loader::guaranteeRows(['project_id' => Ctx::projectId(), 'phase_id' => Ctx::phaseId()]));
        return ['data' => $rows, 'alertCount' => count(array_filter($rows, fn ($g) => $g['needsAttention']))];
    }

    /** Les relances des garanties sont pilotées par un administrateur de la coordination nationale ou le super-admin. */
    private static function canSteerAlerts(): bool
    {
        return Ctx::isAdmin() && Ctx::isNationalCoordination();
    }

    private function needSteerAlerts(): void
    {
        abort_unless(self::canSteerAlerts(), 403, 'Les relances des garanties sont pilotées par un administrateur de la coordination nationale ou le super-administrateur.');
    }

    /** Séquence des relances adressées aux entreprises : paliers, état de chaque garantie, prochain envoi. */
    public function alerts()
    {
        $pid = Ctx::projectId();
        $smtp = DB::table('smtp_settings')->where('project_id', $pid)->first();
        return [
            'enabled' => GuaranteeAlerts::enabled($pid), 'levels' => GuaranteeAlerts::levels($pid), 'canManage' => self::canSteerAlerts(),
            'smtpReady' => $smtp && trim((string) $smtp->host) !== '' && trim((string) $smtp->sender_email) !== '',
            'staff' => AlertEngine::recipients($pid, 'GARANTIE', null), 'rows' => GuaranteeAlerts::plan($pid),
        ];
    }

    public function updateAlertSettings(Request $r)
    {
        $this->needSteerAlerts();
        $d = $r->validate([
            'is_enabled' => 'required|boolean',
            'vigilance' => 'required|array|min:1|max:5',
            'vigilance.*.from' => 'required|numeric|min:1|max:100|distinct',
            'vigilance.*.perWeek' => 'required|integer|min:1|max:7',
            'retard' => 'required|integer|min:1|max:7',
        ], ['vigilance.*.from.distinct' => 'Deux paliers ne peuvent pas avoir le même seuil.'],
            ['vigilance.*.from' => 'seuil', 'vigilance.*.perWeek' => 'envois par semaine', 'retard' => 'envois par semaine en retard']);
        $pid = Ctx::projectId();
        $levels = GuaranteeAlerts::normalizeLevels(['vigilance' => $d['vigilance'], 'retard' => $d['retard']]);
        $before = ['is_enabled' => GuaranteeAlerts::enabled($pid), 'levels' => GuaranteeAlerts::levels($pid)];
        DB::table('alert_rules')->updateOrInsert(['project_id' => $pid, 'category' => 'GARANTIE'],
            ['is_enabled' => $d['is_enabled'], 'levels' => json_encode($levels), 'interval_days' => 7, 'max_sends' => 3, 'updated_at' => now(), 'created_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'AlertRule', 'GARANTIE', 'Séquence des relances des garanties modifiée.', $before, ['is_enabled' => $d['is_enabled'], 'levels' => $levels]);
        return ['message' => $d['is_enabled'] ? 'Séquence des relances enregistrée.' : 'Relances des garanties interrompues pour tous les contrats.'];
    }

    /** Suspendre ou reprendre les relances d'une garantie, ou lui donner sa propre fréquence (null = celle du palier). */
    public function updateAlerts(Request $r, int $id)
    {
        $this->needSteerAlerts();
        $g = $this->gOr404($id);
        $d = $r->validate(['paused' => 'required|boolean', 'reason' => 'nullable|string|max:500', 'per_week' => 'nullable|integer|min:1|max:7'],
            [], ['reason' => 'motif', 'per_week' => 'envois par semaine']);
        $reason = trim((string) ($d['reason'] ?? ''));
        $pausing = $d['paused'] && $g->alerts_paused_at === null;
        abort_if($pausing && mb_strlen($reason) < 5, 422, 'Indiquez le motif de l’interruption des relances (5 caractères au moins).');
        $after = [
            'alerts_paused_at' => $d['paused'] ? ($g->alerts_paused_at ?? now()) : null,
            'alerts_paused_by' => $d['paused'] ? ($pausing ? Ctx::user()->id : $g->alerts_paused_by) : null,
            'alerts_pause_reason' => $d['paused'] ? ($pausing ? $reason : $g->alerts_pause_reason) : null,
            'alerts_per_week' => $d['per_week'] ?? null,
        ];
        $before = ['alerts_paused_at' => $g->alerts_paused_at, 'alerts_pause_reason' => $g->alerts_pause_reason, 'alerts_per_week' => $g->alerts_per_week];
        DB::table('performance_guarantees')->where('id', $id)->update($after + ['updated_at' => now()]);
        $what = $pausing ? "relances interrompues ({$reason})" : (!$d['paused'] && $g->alerts_paused_at !== null ? 'relances reprises' : 'fréquence des relances modifiée');
        Ctx::auditChange('RELANCES_GARANTIE', 'PerformanceGuarantee', $id, "Garantie du contrat {$g->contract_number} : {$what}"
            . ($after['alerts_per_week'] ? ", {$after['alerts_per_week']} envoi(s) par semaine." : ', fréquence du palier.'), $before, $after);
        return ['message' => $pausing ? 'Relances interrompues pour cette garantie.' : (!$d['paused'] && $g->alerts_paused_at !== null ? 'Relances reprises.' : 'Fréquence des relances enregistrée.')];
    }

    public function update(Request $r, int $id)
    {
        $g = $this->gOr404($id);
        $rate = Dates::decimal($r->input('rate'));
        abort_if($rate === null, 422, 'Taux invalide. Saisissez un nombre entre 0 et 100, par exemple 5,5.');
        abort_if($rate < 0 || $rate > 100, 422, 'Le taux de garantie doit être compris entre 0 et 100 %.');
        $start = Dates::valid($r->input('start_date')) ? Dates::part($r->input('start_date')) : null;
        $end = Dates::valid($r->input('end_date')) ? Dates::part($r->input('end_date')) : null;
        abort_unless($start && $end, 422, 'Les dates de début et de fin sont obligatoires.');
        abort_if($end <= $start, 422, 'La date de fin de garantie doit être postérieure à la date de début.');
        $old = "Banque={$g->bank_name}; compte={$g->account_number}; taux=" . (float) $g->rate . '; début=' . Dates::fr(Dates::part($g->start_date)) . '; fin=' . Dates::fr(Dates::part($g->end_date)) . "; observation={$g->observation}";
        DB::table('performance_guarantees')->where('id', $id)->update([
            'bank_name' => trim((string) $r->input('bank_name', '')), 'account_number' => trim((string) $r->input('account_number', '')), 'rate' => $rate,
            'amount' => round((float) $g->contract_amount * $rate / 100, 2), 'start_date' => $start, 'end_date' => $end, 'observation' => trim((string) $r->input('observation', '')), 'updated_at' => now(),
        ]);
        Ctx::audit('MODIFICATION_GARANTIE', 'PerformanceGuarantee', $id, "Ancienne valeur : {$old}. Nouvelle valeur : Banque=" . trim((string) $r->input('bank_name', '')) . "; taux={$rate}; début=" . Dates::fr($start) . '; fin=' . Dates::fr($end));
        return ['message' => 'Garantie mise à jour et modification enregistrée dans l’audit.'];
    }

    public function extend(Request $r, int $id)
    {
        $g = $this->gOr404($id);
        $start = Dates::valid($r->input('start_date')) ? Dates::part($r->input('start_date')) : null;
        $end = Dates::valid($r->input('end_date')) ? Dates::part($r->input('end_date')) : null;
        $reason = trim((string) $r->input('reason', ''));
        abort_unless($start && $end, 422, 'Les dates du prolongement sont obligatoires.');
        abort_if($end <= $start, 422, 'La date de fin du prolongement doit être postérieure à la date de début.');
        abort_if($end <= Dates::part($g->end_date), 422, 'Un prolongement doit dépasser l’échéance actuelle (' . Dates::fr(Dates::part($g->end_date)) . ').');
        abort_if(mb_strlen($reason) < 5, 422, 'Le motif du prolongement est obligatoire.');
        DB::transaction(function () use ($id, $start, $end, $reason) {
            DB::table('guarantee_extensions')->insert(['performance_guarantee_id' => $id, 'start_date' => $start, 'end_date' => $end, 'type' => 'PROLONGATION', 'reason' => $reason, 'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('performance_guarantees')->where('id', $id)->update(['end_date' => $end, 'updated_at' => now()]);
        });
        Ctx::audit('PROLONGATION_GARANTIE', 'PerformanceGuarantee', $id, "Garantie du contrat {$g->contract_number} prolongée du " . Dates::fr($start) . ' au ' . Dates::fr($end) . ". Motif : {$reason}");
        return ['message' => 'La garantie a été prolongée et le mouvement a été archivé.'];
    }

    /** Renouvellement : nouvelle garantie (banque, compte, taux, échéance) qui remplace la précédente ; l'ancienne reste dans l'historique. */
    public function renew(Request $r, int $id)
    {
        $g = $this->gOr404($id);
        $bank = trim((string) $r->input('bank_name', ''));
        $rate = Dates::decimal($r->input('rate'));
        abort_if($rate === null, 422, 'Taux invalide. Saisissez un nombre entre 0 et 100, par exemple 5,5.');
        abort_if($rate < 0 || $rate > 100, 422, 'Le taux de garantie doit être compris entre 0 et 100 %.');
        abort_if($bank === '', 422, 'La banque émettrice de la nouvelle garantie est obligatoire.');
        $start = Dates::valid($r->input('start_date')) ? Dates::part($r->input('start_date')) : null;
        $end = Dates::valid($r->input('end_date')) ? Dates::part($r->input('end_date')) : null;
        $reason = trim((string) $r->input('reason', ''));
        abort_unless($start && $end, 422, 'Les dates de la nouvelle garantie sont obligatoires.');
        abort_if($end <= $start, 422, 'La date de fin de la garantie renouvelée doit être postérieure à sa date de début.');
        abort_if($end <= Dates::part($g->end_date), 422, 'La garantie renouvelée doit expirer après l’échéance actuelle (' . Dates::fr(Dates::part($g->end_date)) . ').');
        abort_if(mb_strlen($reason) < 5, 422, 'Le motif du renouvellement est obligatoire.');
        $replaced = "banque {$g->bank_name}, compte " . ($g->account_number ?: '—') . ', taux ' . (float) $g->rate . ' %, montant ' . number_format((float) $g->amount, 2, ',', ' ') . ', échéance ' . Dates::fr(Dates::part($g->end_date));
        $text = mb_substr("{$reason} — Garantie remplacée : {$replaced}.", 0, 4000);
        DB::transaction(function () use ($id, $g, $bank, $r, $rate, $start, $end, $text) {
            DB::table('guarantee_extensions')->insert(['performance_guarantee_id' => $id, 'start_date' => $start, 'end_date' => $end, 'type' => 'RENOUVELLEMENT', 'reason' => $text, 'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('performance_guarantees')->where('id', $id)->update([
                'bank_name' => $bank, 'account_number' => trim((string) $r->input('account_number', '')), 'rate' => $rate, 'amount' => round((float) $g->contract_amount * $rate / 100, 2), 'end_date' => $end, 'updated_at' => now(),
            ]);
        });
        Ctx::audit('RENOUVELLEMENT_GARANTIE', 'PerformanceGuarantee', $id, "Garantie du contrat {$g->contract_number} renouvelée jusqu’au " . Dates::fr($end) . " (banque {$bank}, taux {$rate} %). Remplace : {$replaced}. Motif : {$reason}");
        return ['message' => 'La garantie a été renouvelée ; l’ancienne garantie est conservée dans l’historique.'];
    }
}
