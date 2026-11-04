<?php

namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use App\Support\DecompteLedger;
use App\Support\GoodsProcedure;
use App\Support\Loader;
use App\Support\Metrics;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Décomptes et retenues (situation du contrat). Rôles :
 *  - can_manage_payments : saisit les décomptes apportés par l'entreprise ;
 *  - can_validate_decomptes : chef de cellule infrastructures — valide ou rejette chaque ligne (jamais celle qu'il a saisie) ;
 *  - can_view_situation : consultation.
 * Les taux sont figés sur chaque ligne au moment de la saisie : une modification ultérieure du contrat ou de la garantie
 * ne réécrit pas les décomptes déjà présentés.
 */
class DecompteController extends Controller
{
    private function contractOr404(int $id): array
    {
        $c = Loader::contract(Ctx::projectId(), Ctx::phaseId(), $id, true);
        abort_unless($c, 404, 'Contrat introuvable.');
        return $c;
    }

    /** Contrat de service : pas d'avance de démarrage. */
    private static function advanceRate(array $c): float
    {
        return strtoupper((string) $c['category']) === 'SERVICE' ? 0.0 : max(0.0, min(100.0, (float) $c['initial_rate'])) / 100;
    }

    /** Avance perçue : à la signature en général, une fois payée à la demande de l'entreprise pour un marché en procédure BAD ou nationale. */
    private static function advanceReceived(array $c): float
    {
        return GoodsProcedure::governs($c['goods_procedure']) ? Metrics::initialFinancing($c) : DecompteLedger::advanceReceived((float) $c['amount'] - (float) $c['renewed_amount'], self::advanceRate($c));
    }

    private static function guaranteeRate(int $contractId): float
    {
        return (float) DB::table('performance_guarantees')->where('contract_id', $contractId)->orderByDesc('id')->value('rate') / 100;
    }

    private function lines(int $contractId): array
    {
        return DB::table('contract_decomptes as d')
            ->leftJoin('users as s', 's.id', '=', 'd.submitted_by')->leftJoin('users as v', 'v.id', '=', 'd.decided_by')
            ->where('d.project_id', Ctx::projectId())->where('d.contract_id', $contractId)
            ->select('d.*', 's.full_name as submitted_by_name', 'v.full_name as decided_by_name')->get()
            ->map(fn ($d) => [
                'id' => (int) $d->id, 'line_no' => (int) $d->line_no, 'number' => $d->number, 'kind' => $d->kind ?? DecompteLedger::DECOMPTE, 'date' => Dates::part($d->operation_date),
                'gross_amount' => (float) $d->gross_amount, 'advance_rate' => (float) $d->advance_rate, 'guarantee_rate' => (float) $d->guarantee_rate,
                'status' => $d->status, 'observation' => (string) $d->observation, 'submitted_by' => (int) $d->submitted_by,
                'submittedBy' => $d->submitted_by_name, 'decidedBy' => $d->decided_by_name, 'decidedAt' => $d->decided_at ? substr((string) $d->decided_at, 0, 16) : null,
                'decisionDate' => ($d->decision_date ?? null) ? Dates::part($d->decision_date) : null,
                'decisionComment' => $d->decision_comment,
                'is_final' => (bool) ($d->is_final ?? false), 'reception_date' => ($d->reception_date ?? null) ? Dates::part($d->reception_date) : null,
                'penalty_weeks' => (int) ($d->penalty_weeks ?? 0), 'penalty_amount' => (float) ($d->penalty_amount ?? 0),
            ])->all();
    }

    public function index(Request $r)
    {
        $all = Loader::contracts(Ctx::projectId(), Ctx::phaseId());
        $list = array_map(fn ($c) => ['id' => $c['id'], 'number' => $c['number'], 'company' => $c['company'], 'category' => $c['category']], $all);
        if (!$all) return ['contracts' => [], 'selected' => null];
        $wanted = (int) $r->query('contract_id', 0);
        $c = null;
        foreach ($all as $x) if ($x['id'] === $wanted) $c = $x;
        $c = $c ?: $all[0];

        $advanceRate = self::advanceRate($c);
        $advanceReceived = self::advanceReceived($c);
        $rows = DecompteLedger::rows((float) $c['amount'], $advanceReceived, $this->lines($c['id']));
        $me = (int) Ctx::user()->id;
        $totals = DecompteLedger::totals($rows);
        $firstPending = collect($rows)->firstWhere('status', DecompteLedger::PENDING);
        $releaseBlocker = $this->releaseBlocker($c, $rows, $totals);
        $live = array_filter($rows, fn ($x) => $x['status'] !== DecompteLedger::REJECTED && $x['kind'] === DecompteLedger::DECOMPTE);
        $submitBlocker = $this->submitBlocker($c, $rows);

        return [
            'contracts' => $list,
            'selected' => [
                'id' => $c['id'], 'number' => $c['number'], 'company' => $c['company'], 'category' => $c['category'], 'amount' => (float) $c['amount'],
                'advanceRate' => $advanceRate, 'advanceReceived' => $advanceReceived, 'guaranteeRate' => self::guaranteeRate($c['id']),
                'isService' => $advanceRate === 0.0 && strtoupper((string) $c['category']) === 'SERVICE',
                'goodsProcedureLabel' => GoodsProcedure::label($c['goods_procedure']), 'advanceOnRequest' => GoodsProcedure::governs($c['goods_procedure']), 'advanceStatus' => $c['advance']['status'] ?? null,
                'usesPenalty' => $c['goods_procedure'] === GoodsProcedure::NATIONAL, 'penaltyRate' => $c['delay_penalty_rate'], 'penaltyCap' => $c['delay_penalty_cap'], 'endDate' => $c['end_date'],
                'grossSoFar' => round(array_sum(array_column($live, 'gross_amount')), 2),
            ],
            'rows' => array_map(fn ($x) => [
                'id' => $x['id'], 'lineNo' => $x['line_no'], 'number' => $x['number'], 'kind' => $x['kind'], 'date' => $x['date'], 'gross' => $x['gross_amount'],
                'advanceRate' => $x['advance_rate'], 'guaranteeRate' => $x['guarantee_rate'],
                'penalty' => $x['penalty'], 'penaltyWeeks' => $x['penalty_weeks'], 'receptionDate' => $x['reception_date'], 'isFinal' => $x['is_final'], 'advance' => $x['advance'], 'guarantee' => $x['guarantee'], 'net' => $x['net'], 'contractBalance' => $x['contractBalance'], 'advanceBalance' => $x['advanceBalance'],
                'status' => $x['status'], 'observation' => $x['observation'], 'submittedBy' => $x['submittedBy'], 'decidedBy' => $x['decidedBy'], 'decidedAt' => $x['decidedAt'],
                'decisionDate' => $x['decisionDate'],

                'decisionComment' => $x['decisionComment'],
                // Le chef de cellule traite les lignes dans l'ordre : chaque montant payé est alors définitif.
                'canDecide' => Ctx::can('can_validate_decomptes') && $x['status'] === DecompteLedger::PENDING && $x['submitted_by'] !== $me && $firstPending && $firstPending['id'] === $x['id'],
                'waitsForEarlier' => $x['status'] === DecompteLedger::PENDING && $firstPending && $firstPending['id'] !== $x['id'],
            ], $rows),
            'totals' => $totals,
            'nextNumber' => 'DECOMPTE N°' . (count(array_filter($rows, fn ($x) => $x['status'] !== DecompteLedger::REJECTED && $x['kind'] === DecompteLedger::DECOMPTE)) + 1),
            'canSubmit' => Ctx::can('can_manage_payments') && !$c['terminated'] && !$this->hasRelease($rows, $c) && $submitBlocker === null,
            'submitBlocker' => $submitBlocker,
            'release' => ['amount' => $totals['held'], 'canRelease' => Ctx::can('can_manage_payments') && $releaseBlocker === null, 'blocker' => $releaseBlocker],
            'canValidate' => Ctx::can('can_validate_decomptes'),
            // Comptes désignés pour valider ; vide : le super-admin doit encore accorder l'habilitation.
            'validators' => DB::table('users')->where('project_id', Ctx::projectId())->where('is_active', true)->where('is_pending', false)->where('is_super_admin', false)->where('can_validate_decomptes', true)->orderBy('full_name')->pluck('full_name'),
        ];
    }

    /** Dernière ligne de décompte au dernier renouvellement : une retenue reversée avant lui ne solde pas la nouvelle période. */
    private static function renewalLine(array $c): int
    {
        return $c['renewals'] ? max(array_column($c['renewals'], 'last_line_no')) : 0;
    }

    private function hasRelease(array $rows, array $c): bool
    {
        $after = self::renewalLine($c);
        return (bool) array_filter($rows, fn ($x) => $x['kind'] === DecompteLedger::RELEASE && $x['status'] !== DecompteLedger::REJECTED && $x['line_no'] > $after);
    }

    /** Marché en procédure : raison qui empêche d'ajouter un décompte (avance en attente, dernier décompte déjà saisi), ou null. */
    private function submitBlocker(array $c, array $rows): ?string
    {
        if (!GoodsProcedure::governs($c['goods_procedure'])) return null;
        if (($c['advance']['status'] ?? null) === ContractAdvanceController::REQUESTED) return 'Une demande d’avance attend son paiement ou son rejet dans la page Paiements : traitez-la avant de saisir un décompte.';
        if (array_filter($rows, fn ($x) => $x['is_final'] && $x['status'] !== DecompteLedger::REJECTED)) return 'Le dernier décompte de ce contrat est déjà enregistré.';
        return null;
    }

    /**
     * Procédure nationale : le décompte qui atteint le montant du marché, ou marqué comme dernier, porte la pénalité de retard.
     * Elle se calcule de la fin du contrat à la réception des biens, et elle est figée sur la ligne.
     */
    private function closingFields(Request $r, array $c, float $gross, float $advanceRate, float $guaranteeRate, array $lines): array
    {
        $none = ['is_final' => false, 'reception_date' => null, 'penalty_weeks' => 0, 'penalty_amount' => 0];
        if ($c['goods_procedure'] !== GoodsProcedure::NATIONAL) return $none;
        $live = array_filter($lines, fn ($l) => $l['status'] !== DecompteLedger::REJECTED && $l['kind'] === DecompteLedger::DECOMPTE);
        if (!$r->boolean('is_final') && array_sum(array_column($live, 'gross_amount')) + $gross < (float) $c['amount'] - 0.005) return $none;
        $reception = Dates::valid($r->input('reception_date')) ? Dates::part($r->input('reception_date')) : null;
        if (!$reception) throw ValidationException::withMessages(['reception_date' => 'Indiquez la date de réception des biens : elle fixe la pénalité de retard.']);
        if ($reception > Dates::today()) throw ValidationException::withMessages(['reception_date' => 'La date ne peut pas être postérieure à aujourd’hui.']);
        $penalty = GoodsProcedure::penalty((float) $c['amount'], $c['delay_penalty_rate'], $c['delay_penalty_cap'], $c['end_date'], $reception);
        $probe = DecompteLedger::rows((float) $c['amount'], self::advanceReceived($c), array_merge($lines, [[
            'id' => 0, 'line_no' => PHP_INT_MAX, 'gross_amount' => $gross, 'advance_rate' => $advanceRate, 'guarantee_rate' => $guaranteeRate, 'status' => DecompteLedger::PENDING, 'penalty_amount' => $penalty['amount'],
        ]]));
        return ['is_final' => true, 'reception_date' => $reception, 'penalty_weeks' => $penalty['weeks'], 'penalty_amount' => end($probe)['penalty']];
    }

    /** Raison qui empêche de reverser la retenue de garantie, ou null si c'est possible. */
    private function releaseBlocker(array $c, array $rows, array $totals): ?string
    {
        if ($this->hasRelease($rows, $c)) return 'La retenue de garantie a déjà été reversée (ligne « RETENUE »).';
        if (\App\Support\Metrics::status($c) !== 'Terminé') return 'La retenue de garantie est reversée en fin de contrat, quand le contrat est terminé.';
        if ($totals['pending'] > 0) return 'Des décomptes attendent encore la décision du chef de cellule infrastructures.';
        if ($totals['held'] <= 0) return 'Aucune retenue de garantie validée à reverser.';
        return null;
    }

    /** Ligne « RETENUE » : reverse à l'entreprise la retenue de garantie cumulée ; elle suit le même circuit de validation. */
    public function release(Request $r, int $id)
    {
        Ctx::need('can_manage_payments');
        $c = $this->contractOr404($id);
        abort_if(GoodsProcedure::stockOnly($c['goods_procedure']), 422, GoodsProcedure::STOCK_ONLY_MESSAGE);
        $d = $r->validate(['operation_date' => 'required|date_format:Y-m-d', 'observation' => 'nullable|string|max:500'], [], ['operation_date' => 'date']);
        if ($d['operation_date'] > Dates::today()) throw ValidationException::withMessages(['operation_date' => 'La date ne peut pas être postérieure à aujourd’hui.']);
        return DB::transaction(function () use ($c, $d) {
            DB::table('contracts')->where('id', $c['id'])->lockForUpdate()->first();
            $rows = DecompteLedger::rows((float) $c['amount'], self::advanceReceived($c), $this->lines($c['id']));
            $totals = DecompteLedger::totals($rows);
            if ($why = $this->releaseBlocker($c, $rows, $totals)) abort(422, $why);
            $row = [
                'project_id' => Ctx::projectId(), 'contract_id' => $c['id'], 'line_no' => (int) DB::table('contract_decomptes')->where('contract_id', $c['id'])->max('line_no') + 1,
                'number' => 'RETENUE', 'kind' => DecompteLedger::RELEASE, 'operation_date' => $d['operation_date'], 'gross_amount' => $totals['held'],
                'advance_rate' => 0, 'guarantee_rate' => 0, 'status' => DecompteLedger::PENDING, 'observation' => isset($d['observation']) ? trim($d['observation']) : 'Reversement de la retenue de garantie de bonne exécution.',
                'submitted_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
            ];
            $id = DB::table('contract_decomptes')->insertGetId($row);
            $text = "Retenue de garantie du contrat {$c['number']} à reverser : " . number_format($row['gross_amount'], 2, ',', ' ') . ' USD, transmise au chef de cellule infrastructures.';
            Ctx::auditChange('CREATION', 'Decompte', $id, $text, null, $row);
            $this->notifyValidators($c, $id, 'Retenue à reverser — contrat ' . $c['number'], $text);
            return ['message' => 'Ligne « RETENUE » enregistrée et transmise au chef de cellule infrastructures pour validation.', 'id' => $id];
        });
    }

    public function store(Request $r, int $id)
    {
        Ctx::need('can_manage_payments');
        $c = $this->contractOr404($id);
        abort_if(GoodsProcedure::stockOnly($c['goods_procedure']), 422, GoodsProcedure::STOCK_ONLY_MESSAGE);
        abort_if($c['terminated'], 422, 'Ce contrat est résilié : aucun décompte ne peut être ajouté.');
        abort_if(DB::table('contract_decomptes')->where('contract_id', $c['id'])->where('kind', DecompteLedger::RELEASE)->where('status', '!=', DecompteLedger::REJECTED)->where('line_no', '>', self::renewalLine($c))->exists(),
            422, 'La retenue de garantie a déjà été reversée : le contrat est soldé.');
        $d = $r->validate([
            'number' => 'required|string|max:60',
            'operation_date' => 'required|date_format:Y-m-d',
            'observation' => 'nullable|string|max:500',
        ], [], ['number' => 'N° du décompte', 'operation_date' => 'date']);
        if ($d['operation_date'] > Dates::today()) throw ValidationException::withMessages(['operation_date' => 'La date ne peut pas être postérieure à aujourd’hui.']);
        $gross = Dates::decimal($r->input('gross_amount'));
        if ($gross === null || $gross <= 0) throw ValidationException::withMessages(['gross_amount' => 'Saisissez le montant du décompte, supérieur à zéro.']);

        return DB::transaction(function () use ($r, $c, $d, $gross) {
            DB::table('contracts')->where('id', $c['id'])->lockForUpdate()->first();
            $lines = $this->lines($c['id']);
            if ($why = $this->submitBlocker($c, DecompteLedger::rows((float) $c['amount'], self::advanceReceived($c), $lines))) abort(422, $why);
            $closing = $this->closingFields($r, $c, round($gross, 2), self::advanceRate($c), self::guaranteeRate($c['id']), $lines);
            $exists = DB::table('contract_decomptes')->where('contract_id', $c['id'])->where('status', '!=', DecompteLedger::REJECTED)
                ->whereRaw('LOWER(number) = ?', [mb_strtolower(trim($d['number']))])->exists();
            if ($exists) throw ValidationException::withMessages(['number' => 'Ce numéro de décompte existe déjà pour ce contrat.']);
            $row = [
                'project_id' => Ctx::projectId(), 'contract_id' => $c['id'],
                'line_no' => (int) DB::table('contract_decomptes')->where('contract_id', $c['id'])->max('line_no') + 1,
                'number' => trim($d['number']), 'operation_date' => $d['operation_date'], 'gross_amount' => round($gross, 2),
                'advance_rate' => self::advanceRate($c), 'guarantee_rate' => self::guaranteeRate($c['id']),
                'status' => DecompteLedger::PENDING, 'observation' => isset($d['observation']) ? trim($d['observation']) : null,
                'submitted_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
            ] + $closing;
            $id = DB::table('contract_decomptes')->insertGetId($row);
            $text = "{$row['number']} du contrat {$c['number']} ({$c['company']}) : " . number_format($row['gross_amount'], 2, ',', ' ') . ' USD, transmis au chef de cellule infrastructures.';
            Ctx::auditChange('CREATION', 'Decompte', $id, $text, null, $row);
            $this->notifyValidators($c, $id, "{$row['number']} à valider — contrat {$c['number']}", $text);
            $penaltyNote = $closing['penalty_amount'] > 0 ? ' Pénalité de retard déduite : ' . number_format($closing['penalty_amount'], 2, ',', ' ') . " USD ({$closing['penalty_weeks']} semaine(s) de retard)." : '';
            return ['message' => "{$row['number']} enregistré et transmis au chef de cellule infrastructures pour validation.{$penaltyNote}", 'id' => $id];
        });
    }

    /** Décision du chef de cellule infrastructures : VALIDE ou REJETE (motif et date de l'opération obligatoires pour un rejet). */
    public function decide(Request $r, int $id)
    {
        Ctx::need('can_validate_decomptes');
        $d = $r->validate([
            'decision' => ['required', Rule::in([DecompteLedger::VALID, DecompteLedger::REJECTED])],
            'comment' => 'nullable|string|max:500|required_if:decision,' . DecompteLedger::REJECTED,
            'decision_date' => 'nullable|date_format:Y-m-d|required_if:decision,' . DecompteLedger::REJECTED,
        ], [
            'comment.required_if' => 'Indiquez le motif du rejet.',
            'decision_date.required_if' => 'Indiquez la date de l’opération de rejet.',
        ], ['comment' => 'motif', 'decision_date' => 'date de l’opération']);
        $line = DB::table('contract_decomptes')->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($line, 404, 'Décompte introuvable.');
        $c = $this->contractOr404((int) $line->contract_id);
        abort_if($line->status !== DecompteLedger::PENDING, 422, 'Ce décompte a déjà fait l’objet d’une décision.');
        abort_if((int) $line->submitted_by === (int) Ctx::user()->id, 422, 'Vous ne pouvez pas valider un décompte que vous avez saisi vous-même.');
        abort_if(DB::table('contract_decomptes')->where('contract_id', $line->contract_id)->where('status', DecompteLedger::PENDING)->where('line_no', '<', $line->line_no)->exists(),
            422, 'Traitez d’abord les décomptes précédents de ce contrat, dans l’ordre des lignes.');
        $date = $d['decision_date'] ?? Dates::today();
        if ($date > Dates::today()) throw ValidationException::withMessages(['decision_date' => 'La date ne peut pas être postérieure à aujourd’hui.']);
        if ($date < Dates::part($line->operation_date)) throw ValidationException::withMessages(['decision_date' => 'La date ne peut pas précéder celle du décompte (' . Dates::fr(Dates::part($line->operation_date)) . ').']);

        $after = ['status' => $d['decision'], 'decided_by' => Ctx::user()->id, 'decided_at' => now()->toDateTimeString(), 'decision_date' => $date,
            'decision_comment' => isset($d['comment']) ? trim($d['comment']) : null];
        DB::table('contract_decomptes')->where('id', $id)->update($after + ['updated_at' => now()]);
        $verb = $d['decision'] === DecompteLedger::VALID ? 'validé' : 'rejeté';
        Ctx::auditChange($d['decision'] === DecompteLedger::VALID ? 'VALIDATION' : 'REJET', 'Decompte', $id,
            "{$line->number} du contrat {$c['number']} {$verb} par le chef de cellule infrastructures le " . Dates::fr($date) . ($after['decision_comment'] ? " : {$after['decision_comment']}" : '.'), ['status' => $line->status], $after);
        Notifier::resolve('Decompte', $id, 'DECOMPTE_A_VALIDER');
        if ($line->submitted_by) {
            Notifier::send([(int) $line->submitted_by], 'DECOMPTE_DECISION', "{$line->number} {$verb} — contrat {$c['number']}",
                "{$line->number} du contrat {$c['number']} {$verb} par " . Ctx::user()->full_name . ' le ' . Dates::fr($date) . ($after['decision_comment'] ? " : {$after['decision_comment']}" : '.'),
                '/decomptes?contrat=' . $c['id'], 'Decompte', $id);
        }
        if ($d['decision'] === DecompteLedger::VALID) self::notifyPayers($c, (int) $id);
        return ['message' => "{$line->number} {$verb}."];
    }

    /** Lignes du décompte d'un contrat, avec retenues et montant payé, comme dans la situation du contrat. */
    public static function ledger(array $c): array
    {
        return DecompteLedger::rows((float) $c['amount'], self::advanceReceived($c), (new self())->lines($c['id']));
    }

    /**
     * Décomptes validés qui attendent leur paiement : le gestionnaire vérifie le montant (colonne MONTANT PAYÉ de la ligne)
     * et renseigne le paiement dans la page Paiements. Un paiement par décompte (contract_payments.decompte_id).
     */
    public static function unpaid(array $contracts): array
    {
        $open = array_values(array_filter($contracts, fn ($c) => !$c['terminated']));
        if (!$open) return [];
        $paid = DB::table('contract_payments')->whereNotNull('decompte_id')->whereIn('contract_id', array_column($open, 'id'))->pluck('decompte_id')->map(fn ($x) => (int) $x)->flip();
        $waiting = DB::table('contract_decomptes')->whereIn('contract_id', array_column($open, 'id'))->where('status', DecompteLedger::VALID)->pluck('contract_id')->map(fn ($x) => (int) $x)->flip();
        $out = [];
        foreach ($open as $c) {
            if (!isset($waiting[$c['id']])) continue;
            foreach (self::ledger($c) as $l) {
                if ($l['status'] !== DecompteLedger::VALID || isset($paid[$l['id']])) continue;
                $out[] = ['id' => $l['id'], 'number' => $l['number'], 'kind' => $l['kind'], 'contractId' => $c['id'], 'contractNumber' => $c['number'], 'company' => $c['company'],
                    'amount' => $l['net'], 'decisionDate' => $l['decisionDate'], 'decidedBy' => $l['decidedBy']];
            }
        }
        return $out;
    }

    /** Message au gestionnaire des paiements : le décompte validé mérite le paiement de la facture. */
    public static function notifyPayers(array $c, int $id): void
    {
        $line = collect(self::ledger($c))->firstWhere('id', $id);
        if (!$line) return;
        Notifier::send(Notifier::holders(Ctx::projectId(), 'can_manage_payments'), 'DECOMPTE_A_PAYER', "{$line['number']} validé — paiement à renseigner",
            "{$line['number']} du contrat {$c['number']} ({$c['company']}) est validé : il mérite le paiement de la facture de " . number_format($line['net'], 2, ',', ' ') . ' USD.',
            '/payments?contrat=' . $c['id'], 'Decompte', $id);
    }

    /** Chaque décompte transmis arrive dans l'espace du chef de cellule infrastructures (comptes habilités à valider les décomptes). */
    private function notifyValidators(array $c, int $id, string $title, string $text): void
    {
        Notifier::send(Notifier::holders(Ctx::projectId(), 'can_validate_decomptes'), 'DECOMPTE_A_VALIDER', $title, $text . ' Saisi par ' . Ctx::user()->full_name . '.',
            '/decomptes?contrat=' . $c['id'], 'Decompte', $id);
    }
}
