<?php

namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use App\Support\GoodsProcedure;
use App\Support\Loader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function store(Request $r, int $id)
    {
        Ctx::need('can_manage_payments');
        $c = $this->contractOr404($id);
        abort_unless(GoodsProcedure::governs($c['goods_procedure']), 422, 'L’avance sur demande concerne les marchés de biens en procédure BAD ou nationale.');
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut plus recevoir de demande d’avance.');
        abort_if($c['initial_rate'] <= 0, 422, 'Ce contrat ne prévoit pas d’avance de démarrage.');
        $d = $r->validate([
            'request_date' => 'required|date_format:Y-m-d',
            'request_reference' => 'required|string|max:120',
            'guarantee_bank' => 'required|string|max:191',
            'guarantee_number' => 'required|string|max:120',
            'guarantee_end_date' => 'required|date_format:Y-m-d',
        ], [], ['request_date' => 'date de la demande', 'request_reference' => 'référence de la demande', 'guarantee_bank' => 'banque', 'guarantee_number' => 'n° de la garantie', 'guarantee_end_date' => 'fin de validité']);
        $e = [];
        if ($d['request_date'] > Dates::today()) $e['request_date'] = 'La date ne peut pas être postérieure à aujourd’hui.';
        if ($d['guarantee_end_date'] < $d['request_date']) $e['guarantee_end_date'] = 'La garantie doit rester valable après la date de la demande.';
        $amount = round((float) $c['amount'] * (float) $c['initial_rate'] / 100, 2);
        $guarantee = Dates::decimal($r->input('guarantee_amount'));
        if ($guarantee === null || $guarantee < $amount) $e['guarantee_amount'] = 'La garantie de restitution doit couvrir l’avance, soit au moins ' . number_format($amount, 2, ',', ' ') . ' USD.';
        if ($e) throw ValidationException::withMessages($e);

        $advanceId = DB::transaction(function () use ($c, $d, $amount, $guarantee) {
            DB::table('contracts')->where('id', $c['id'])->lockForUpdate()->first();
            abort_if(DB::table('contract_advances')->where('contract_id', $c['id'])->where('status', '!=', self::REJECTED)->exists(), 422, 'Une demande d’avance est déjà enregistrée pour ce contrat.');
            abort_if(self::hasDecomptes($c['id']), 422, 'Un décompte est déjà enregistré : l’avance ne peut plus être demandée pour ce contrat.');
            return DB::table('contract_advances')->insertGetId([
                'contract_id' => $c['id'], 'request_date' => $d['request_date'], 'request_reference' => trim($d['request_reference']), 'rate' => $c['initial_rate'], 'amount' => $amount,
                'guarantee_bank' => trim($d['guarantee_bank']), 'guarantee_number' => trim($d['guarantee_number']), 'guarantee_amount' => round($guarantee, 2), 'guarantee_end_date' => $d['guarantee_end_date'],
                'status' => self::REQUESTED, 'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        Ctx::audit('CREATION', 'ContractAdvance', $advanceId, "Demande d’avance du contrat {$c['number']} : " . number_format($amount, 2, ',', ' ') . " USD, garantie de restitution n° {$d['guarantee_number']} ({$d['guarantee_bank']}).");
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
