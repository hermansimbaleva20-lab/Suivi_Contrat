<?php

namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use App\Support\GoodsProcedure;
use App\Support\Loader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Avance de démarrage d'un marché de biens en procédure BAD ou nationale : l'entreprise la demande en présentant une garantie
 * de restitution d'un montant équivalent ; elle ne compte comme versée qu'une fois son paiement enregistré.
 */
class ContractAdvanceController extends Controller
{
    public const REQUESTED = 'DEMANDEE';
    public const PAID = 'PAYEE';
    public const REJECTED = 'REJETEE';

    private function contractOr404(int $id): array
    {
        $c = Loader::contract(Ctx::projectId(), Ctx::phaseId(), $id, true);
        abort_unless($c, 404, 'Contrat introuvable.');
        return $c;
    }

    private function advanceOr404(int $id): array
    {
        $a = DB::table('contract_advances')->where('id', $id)->first();
        abort_unless($a, 404, 'Demande d’avance introuvable.');
        return [$a, $this->contractOr404((int) $a->contract_id)];
    }

    /** Le décompte retient l'avance sur son solde : elle doit être réglée avant le premier décompte, sinon les lignes déjà calculées changeraient. */
    private static function hasDecomptes(int $contractId): bool
    {
        return DB::table('contract_decomptes')->where('contract_id', $contractId)->where('status', '!=', 'REJETE')->exists();
    }

    /** La date de la caution existe (base mise à jour par la migration du 23/11/2026 ou V41). */
    public static function hasIssueDate(): bool
    {
        static $ready = null;
        return $ready ??= Schema::hasColumn('contract_advances', 'guarantee_issue_date');
    }

    /** Champs d'une demande d'avance, saisis ici (Paiements) ou dans la modification du contrat (champs préfixés « advance_request_ »). */
    public const FIELDS = ['request_reference', 'request_date', 'guarantee_number', 'guarantee_bank', 'guarantee_issue_date', 'guarantee_end_date', 'guarantee_amount'];

    private const LABELS = ['request_date' => 'date de la demande', 'request_reference' => 'n° de référence de l’avance', 'guarantee_bank' => 'banque', 'guarantee_number' => 'n° de la caution',
        'guarantee_issue_date' => 'date de la caution', 'guarantee_end_date' => 'date d’expiration'];

    /**
     * Contrôle une demande d'avance, à l'enregistrement comme à sa correction : [erreurs par champ, valeurs retenues].
     * $signedOn : signature du contrat (à défaut son démarrage) ; $advance : montant de l'avance, que la caution doit couvrir ;
     * $paidOn : date du paiement d'une avance déjà payée, que la demande ne peut pas suivre.
     */
    public static function check(array $in, ?string $signedOn, float $advance, ?string $paidOn = null): array
    {
        $e = [];
        $d = [];
        foreach (['request_reference' => 120, 'guarantee_number' => 120, 'guarantee_bank' => 191] as $k => $max) {
            $d[$k] = trim((string) ($in[$k] ?? ''));
            if ($d[$k] === '') $e[$k] = 'Le champ « ' . self::LABELS[$k] . ' » est obligatoire.';
            elseif (mb_strlen($d[$k]) > $max) $e[$k] = 'Le champ « ' . self::LABELS[$k] . " » ne doit pas dépasser {$max} caractères.";
        }
        foreach (['request_date', 'guarantee_issue_date', 'guarantee_end_date'] as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            $d[$k] = $v !== '' && Dates::valid($v) ? Dates::part($v) : null;
            if ($v !== '' && !$d[$k]) $e[$k] = 'Le champ « ' . self::LABELS[$k] . ' » doit être une date valide.';
            elseif (!$d[$k] && ($k !== 'guarantee_issue_date' || self::hasIssueDate())) $e[$k] = 'Le champ « ' . self::LABELS[$k] . ' » est obligatoire.';
        }
        if ($d['request_date'] && $d['request_date'] > Dates::today()) $e['request_date'] = 'La date ne peut pas être postérieure à aujourd’hui.';
        elseif ($d['request_date'] && $paidOn && $d['request_date'] > $paidOn) $e['request_date'] = 'La demande ne peut pas suivre le paiement de l’avance (' . Dates::fr($paidOn) . ').';
        // L'avance se donne après la signature du contrat (décision du 23/11/2026) : la date de sa caution ne la précède jamais.
        // Dates de la caution (23/11/2026) : les siennes, distinctes de celles du contrat ; valable au moins jusqu'à la demande.
        $issue = $d['guarantee_issue_date'];
        if ($issue && $signedOn && $issue < $signedOn) $e['guarantee_issue_date'] = 'L’avance de démarrage vient après la signature du contrat : la date de la caution ne peut pas être antérieure au ' . Dates::fr($signedOn) . '.';
        if ($d['guarantee_end_date'] && !isset($e['guarantee_end_date'])) {
            if ($issue && $d['guarantee_end_date'] < $issue) $e['guarantee_end_date'] = 'La date d’expiration doit suivre la date de la caution.';
            elseif ($d['request_date'] && $d['guarantee_end_date'] < $d['request_date']) $e['guarantee_end_date'] = 'La caution doit rester valable après la date de la demande.';
        }
        $d['guarantee_amount'] = Dates::decimal($in['guarantee_amount'] ?? null);
        if ($d['guarantee_amount'] === null || $d['guarantee_amount'] < $advance) $e['guarantee_amount'] = 'La garantie de restitution doit couvrir l’avance, soit au moins ' . number_format($advance, 2, ',', ' ') . ' USD.';
        else $d['guarantee_amount'] = round($d['guarantee_amount'], 2);
        return [$e, $d];
    }

    /** Colonnes de contract_advances écrites à partir des valeurs retenues par check(). */
    public static function columns(array $d): array
    {
        return ['request_date' => $d['request_date'], 'request_reference' => $d['request_reference'], 'guarantee_bank' => $d['guarantee_bank'], 'guarantee_number' => $d['guarantee_number'],
            'guarantee_amount' => $d['guarantee_amount'], 'guarantee_end_date' => $d['guarantee_end_date']] + (self::hasIssueDate() ? ['guarantee_issue_date' => $d['guarantee_issue_date']] : []);
    }

    /** Une demande d'avance ne s'enregistre plus après le premier décompte. */
    public static function blockedByDecomptes(int $contractId): bool
    {
        return self::hasDecomptes($contractId);
    }

    public static function auditText(array $d, string $number, float $amount): string
    {
        return "Demande d’avance n° {$d['request_reference']} du contrat {$number} : " . number_format($amount, 2, ',', ' ') . " USD, caution n° {$d['guarantee_number']} ({$d['guarantee_bank']})"
            . ($d['guarantee_issue_date'] ? ' du ' . Dates::fr($d['guarantee_issue_date']) : '') . ' au ' . Dates::fr($d['guarantee_end_date']) . '.';
    }

    public function store(Request $r, int $id)
    {
        Ctx::need('can_manage_payments');
        $c = $this->contractOr404($id);
        abort_unless(GoodsProcedure::governs($c['goods_procedure']), 422, 'L’avance sur demande concerne les marchés de biens en procédure BAD ou nationale.');
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut plus recevoir de demande d’avance.');
        abort_if($c['initial_rate'] <= 0, 422, 'Ce contrat ne prévoit pas d’avance de démarrage.');
        $amount = round((float) $c['amount'] * (float) $c['initial_rate'] / 100, 2);
        [$e, $d] = self::check($r->only(self::FIELDS), $c['signature_date'] ?: $c['start_date'], $amount);
        if ($e) throw ValidationException::withMessages($e);

        $advanceId = DB::transaction(function () use ($c, $d, $amount) {
            DB::table('contracts')->where('id', $c['id'])->lockForUpdate()->first();
            abort_if(DB::table('contract_advances')->where('contract_id', $c['id'])->where('status', '!=', self::REJECTED)->exists(), 422, 'Une demande d’avance est déjà enregistrée pour ce contrat.');
            abort_if(self::hasDecomptes($c['id']), 422, 'Un décompte est déjà enregistré : l’avance ne peut plus être demandée pour ce contrat.');
            return DB::table('contract_advances')->insertGetId(self::columns($d) + [
                'contract_id' => $c['id'], 'rate' => $c['initial_rate'], 'amount' => $amount,
                'status' => self::REQUESTED, 'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        Ctx::audit('CREATION', 'ContractAdvance', $advanceId, self::auditText($d, $c['number'], $amount));
        return response()->json(['message' => 'Demande d’avance enregistrée : ' . number_format($amount, 2, ',', ' ') . ' USD à payer.', 'id' => $advanceId], 201);
    }

    public function pay(Request $r, int $id)
    {
        Ctx::need('can_manage_payments');
        [$a, $c] = $this->advanceOr404($id);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut plus recevoir de paiement.');
        abort_if($a->status !== self::REQUESTED, 422, 'Cette demande d’avance a déjà été traitée.');
        $d = $r->validate(['paid_date' => 'required|date_format:Y-m-d', 'payment_reference' => 'required|string|max:120'], [], ['paid_date' => 'date du paiement', 'payment_reference' => 'référence du paiement']);
        if ($d['paid_date'] > Dates::today()) throw ValidationException::withMessages(['paid_date' => 'La date ne peut pas être postérieure à aujourd’hui.']);
        if ($d['paid_date'] < Dates::part($a->request_date)) throw ValidationException::withMessages(['paid_date' => 'La date ne peut pas précéder celle de la demande (' . Dates::fr(Dates::part($a->request_date)) . ').']);
        $after = ['status' => self::PAID, 'paid_date' => $d['paid_date'], 'payment_reference' => trim($d['payment_reference']), 'decided_by' => Ctx::user()->id];
        DB::table('contract_advances')->where('id', $id)->update($after + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'ContractAdvance', $id, "Avance du contrat {$c['number']} payée le " . Dates::fr($d['paid_date']) . ' : ' . number_format((float) $a->amount, 2, ',', ' ') . ' USD.', ['status' => $a->status], $after);
        return ['message' => 'Paiement de l’avance enregistré : ' . number_format((float) $a->amount, 2, ',', ' ') . ' USD.'];
    }

    /** Rejet d'une demande, ou annulation d'un paiement enregistré par erreur tant qu'aucun décompte n'en dépend ; le motif est obligatoire. */
    public function cancel(Request $r, int $id)
    {
        Ctx::need('can_manage_payments');
        [$a, $c] = $this->advanceOr404($id);
        abort_if($a->status === self::REJECTED, 422, 'Cette demande d’avance est déjà rejetée.');
        abort_if($a->status === self::PAID && self::hasDecomptes($c['id']), 422, 'Un décompte retient déjà cette avance : son paiement ne peut plus être annulé.');
        $d = $r->validate(['reason' => 'required|string|min:5|max:500'], ['reason.required' => 'Indiquez le motif.', 'reason.min' => 'Le motif doit compter au moins 5 caractères.'], ['reason' => 'motif']);
        $after = ['status' => self::REJECTED, 'decision_comment' => trim($d['reason']), 'decided_by' => Ctx::user()->id];
        DB::table('contract_advances')->where('id', $id)->update($after + ['updated_at' => now()]);
        Ctx::auditChange('SUPPRESSION', 'ContractAdvance', $id, "Avance du contrat {$c['number']} annulée. Motif : {$after['decision_comment']}", ['status' => $a->status, 'paid_date' => $a->paid_date], $after);
        return ['message' => 'La demande d’avance a été annulée.'];
    }
}
