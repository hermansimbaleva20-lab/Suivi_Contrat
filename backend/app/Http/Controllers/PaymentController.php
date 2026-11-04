<?php
namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use App\Support\DecompteLedger;
use App\Support\GoodsProcedure;
use App\Support\Loader;
use App\Support\Metrics;
use App\Support\Notifier;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    private function contractOr404(int $id): array
    {
        $c = Loader::contract(Ctx::projectId(), Ctx::phaseId(), $id, true);
        abort_unless($c, 404, 'Contrat introuvable.');
        return $c;
    }

    public function index(Request $r)
    {
        $all = Loader::contracts(Ctx::projectId(), Ctx::phaseId());
        if (!$all) return ['contracts' => [], 'selected' => null, 'toPay' => []];
        $sel = null;
        $wanted = (int) $r->query('contract_id', 0);
        foreach ($all as $c) if ($c['id'] === $wanted) $sel = $c;
        $sel = $sel ?: $all[0];
        $payments = array_reverse($sel['payments']);
        $size = 10;
        [$page, $pages, $offset] = Views::paginate(count($payments), (int) $r->query('page', 1), $size);
        return [
            'contracts' => array_map(fn ($c) => ['id' => $c['id'], 'number' => $c['number'], 'company' => $c['company'], 'isTerminated' => $c['terminated']], $all),
            'selected' => Views::contract($sel, false),
            'payments' => array_slice($payments, $offset, $size),
            'toPay' => Ctx::can('can_manage_payments') ? DecompteController::unpaid($all) : [],
            'totalPaid' => Metrics::totalPaid($sel), 'meta' => ['page' => $page, 'pages' => $pages, 'total' => count($payments), 'size' => $size],
        ];
    }

    private function fields(Request $r): array
    {
        $amount = Dates::decimal($r->input('amount'));
        abort_if($amount === null, 422, 'Montant invalide. Saisissez un nombre avec virgule ou point, par exemple 1500,75.');
        abort_if($amount <= 0, 422, 'Le montant payé doit être supérieur à zéro.');
        $date = Dates::valid($r->input('date')) ? Dates::part($r->input('date')) : null;
        abort_unless($date, 422, 'La date du paiement est obligatoire.');
        return [$date, round($amount, 2), trim((string) $r->input('reference', '')), trim((string) $r->input('observation', ''))];
    }

    /**
     * Paiement d'un décompte validé : le montant est celui de la colonne MONTANT PAYÉ de la ligne, jamais un montant saisi ;
     * le gestionnaire renseigne la date, la référence et l'observation. Un seul paiement par décompte.
     */
    private function storeForDecompte(Request $r, array $c, int $decompteId)
    {
        $date = Dates::valid($r->input('date')) ? Dates::part($r->input('date')) : null;
        abort_unless($date, 422, 'La date du paiement est obligatoire.');
        [$id, $number, $amount] = DB::transaction(function () use ($r, $c, $decompteId, $date) {
            $line = DB::table('contract_decomptes')->where('id', $decompteId)->where('contract_id', $c['id'])->lockForUpdate()->first();
            abort_unless($line, 404, 'Décompte introuvable pour ce contrat.');
            abort_unless($line->status === DecompteLedger::VALID, 422, 'Seul un décompte validé peut être payé.');
            abort_if(DB::table('contract_payments')->where('decompte_id', $decompteId)->exists(), 422, "Le paiement de {$line->number} est déjà enregistré.");
            $amount = (float) collect(DecompteController::ledger($c))->firstWhere('id', $decompteId)['net'];
            $id = DB::table('contract_payments')->insertGetId(['contract_id' => $c['id'], 'date' => $date, 'amount' => $amount, 'reference' => trim((string) $r->input('reference', '')) ?: $line->number,
                'observation' => trim((string) $r->input('observation', '')), 'decompte_id' => $decompteId, 'created_at' => now(), 'updated_at' => now()]);
            return [$id, $line->number, $amount];
        });
        Notifier::resolve('Decompte', $decompteId, 'DECOMPTE_A_PAYER');
        Ctx::audit('CREATION', 'ContractPayment', $id, "Paiement de {$number} du contrat {$c['number']}, date " . Dates::fr($date) . ', montant ' . number_format($amount, 2, ',', ' ') . '.');
        return response()->json(['message' => "Paiement de {$number} enregistré : " . number_format($amount, 2, ',', ' ') . ' USD.', 'id' => $id], 201);
    }

    public function store(Request $r)
    {
        Ctx::need('can_manage_payments');
        $c = $this->contractOr404((int) $r->input('contract_id'));
        abort_if(GoodsProcedure::stockOnly($c['goods_procedure']), 422, GoodsProcedure::STOCK_ONLY_MESSAGE);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut plus recevoir de nouveau paiement.');
        if ($r->filled('decompte_id')) return $this->storeForDecompte($r, $c, (int) $r->input('decompte_id'));
        [$date, $amount, $ref, $obs] = $this->fields($r);
        $id = DB::table('contract_payments')->insertGetId(['contract_id' => $c['id'], 'date' => $date, 'amount' => $amount, 'reference' => $ref, 'observation' => $obs, 'created_at' => now(), 'updated_at' => now()]);
        Ctx::audit('CREATION', 'ContractPayment', $id, "Paiement du contrat {$c['number']}, date " . Dates::fr($date) . ', montant ' . number_format($amount, 2, ',', ' ') . '.');
        return response()->json(['message' => 'Paiement de ' . number_format($amount, 2, ',', ' ') . ' enregistré.', 'id' => $id], 201);
    }

    private function paymentOr404(int $id): array
    {
        $p = DB::table('contract_payments')->where('id', $id)->first();
        abort_unless($p, 404, 'Paiement introuvable.');
        return [$p, $this->contractOr404((int) $p->contract_id)];
    }

    public function update(Request $r, int $id)
    {
        Ctx::need('can_manage_payments');
        [$p, $c] = $this->paymentOr404($id);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne permet plus la modification d’un paiement.');
        // Le montant d'un paiement de décompte reste celui de la colonne MONTANT PAYÉ : seuls la date et les références changent.
        if (!empty($p->decompte_id)) $r->merge(['amount' => (string) $p->amount]);
        [$date, $amount, $ref, $obs] = $this->fields($r);
        $old = 'date ' . Dates::fr(Dates::part($p->date)) . ', montant ' . number_format((float) $p->amount, 2, ',', ' ');
        DB::table('contract_payments')->where('id', $id)->update(['date' => $date, 'amount' => $amount, 'reference' => $ref, 'observation' => $obs, 'updated_at' => now()]);
        Ctx::audit('MODIFICATION', 'ContractPayment', $id, "Contrat {$c['number']}. Ancien paiement : {$old}. Nouveau paiement : date " . Dates::fr($date) . ', montant ' . number_format($amount, 2, ',', ' ') . '. Correction d’une erreur d’enregistrement.');
        return ['message' => 'Le paiement a été corrigé.'];
    }

    public function destroy(Request $r, int $id)
    {
        Ctx::need('can_manage_payments');
        $reason = trim((string) $r->input('reason', ''));
        abort_if(mb_strlen($reason) < 5, 422, 'Une raison valable (minimum 5 caractères) est obligatoire pour supprimer un paiement.');
        [$p, $c] = $this->paymentOr404($id);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne permet plus la suppression d’un paiement.');
        DB::table('contract_payments')->where('id', $id)->delete();
        // Sans son paiement, le décompte validé revient dans les décomptes à payer.
        if (!empty($p->decompte_id)) DecompteController::notifyPayers($c, (int) $p->decompte_id);
        Ctx::audit('SUPPRESSION', 'ContractPayment', $id, "Contrat {$c['number']}. Paiement du " . Dates::fr(Dates::part($p->date)) . ', montant ' . number_format((float) $p->amount, 2, ',', ' ') . ", référence {$p->reference}. Motif : {$reason}");
        return ['message' => 'Le paiement a été supprimé.'];
    }
}
