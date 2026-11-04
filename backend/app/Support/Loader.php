<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Chargement des contrats et de leurs dépendances sous forme de tableaux normalisés (voir Metrics). */
class Loader
{
    /** @return array<int,array> */
    public static function contracts(int $projectId, int $phaseId = 0, ?array $ids = null, bool $allPhases = false, bool $withStockOnly = false): array
    {
        $q = DB::table('contracts as c')
            ->leftJoin('companies as co', 'co.id', '=', 'c.company_id')
            ->leftJoin('project_phases as ph', 'ph.id', '=', 'c.phase_id')
            ->leftJoin('organizational_units as ou', 'ou.id', '=', 'c.organizational_unit_id')
            ->where('c.project_id', $projectId)
            ->select('c.*', 'co.name as company_name', 'ph.name as phase_name', 'ph.number as phase_number', 'ou.name as unit_name')
            ->orderBy('c.reference');
        // Les fournitures de bureau ne se suivent que par le stock : hors indicateurs, paiements, alertes et rapports.
        if ($ids === null && !$withStockOnly) $q->where(fn ($w) => $w->whereNull('c.goods_procedure')->orWhere('c.goods_procedure', '!=', GoodsProcedure::OFFICE));
        if (!$allPhases && $phaseId > 0) $q->where('c.phase_id', $phaseId);
        if ($ids !== null) {
            if (!$ids) return [];
            $q->whereIn('c.id', $ids);
        }
        return self::attach($q->get());
    }

    public static function contract(int $projectId, int $phaseId, int $id, bool $allPhases = true): ?array
    {
        $list = self::contracts($projectId, $phaseId, [$id], $allPhases);
        return $list ? $list[0] : null;
    }

    private static function attach($rows): array
    {
        if ($rows->isEmpty()) return [];
        $ids = $rows->pluck('id')->all();

        $sitesBy = [];
        foreach (DB::table('contract_sites')->whereIn('contract_id', $ids)->orderBy('id')->get() as $s) {
            $sitesBy[$s->contract_id][$s->id] = [
                'id' => (int) $s->id, 'name' => (string) $s->name, 'province' => (string) $s->province, 'work_type' => (string) $s->work_type,
                'description' => (string) $s->description, 'quantity' => (float) $s->quantity, 'entries' => [],
            ];
        }
        $entriesBy = [];
        foreach (DB::table('progress_entries')->whereIn('contract_id', $ids)->orderBy('situation_date')->orderBy('id')->get() as $e) {
            $entry = [
                'id' => (int) $e->id, 'site_id' => $e->contract_site_id ? (int) $e->contract_site_id : null, 'date' => Dates::part($e->situation_date),
                'progress' => (float) $e->progress, 'paid' => (float) $e->paid_amount, 'realization' => (string) $e->realization_physical, 'observation' => (string) $e->observation,
            ];
            $entriesBy[$e->contract_id][] = $entry;
            if ($entry['site_id'] && isset($sitesBy[$e->contract_id][$entry['site_id']])) $sitesBy[$e->contract_id][$entry['site_id']]['entries'][] = $entry;
        }
        $paymentsBy = [];
        foreach (DB::table('contract_payments')->whereIn('contract_id', $ids)->orderBy('date')->orderBy('id')->get() as $p) {
            $paymentsBy[$p->contract_id][] = ['id' => (int) $p->id, 'date' => Dates::part($p->date), 'amount' => (float) $p->amount, 'reference' => (string) $p->reference, 'observation' => (string) $p->observation,
                'decompteId' => !empty($p->decompte_id) ? (int) $p->decompte_id : null]; // paiement d'un décompte validé : son montant est celui de la colonne MONTANT PAYÉ
        }
        $advanceBy = [];
        foreach (DB::table('contract_advances')->whereIn('contract_id', $ids)->where('status', '!=', 'REJETEE')->orderBy('id')->get() as $a) {
            $advanceBy[$a->contract_id] = [
                'id' => (int) $a->id, 'status' => (string) $a->status, 'rate' => (float) $a->rate, 'amount' => (float) $a->amount,
                'request_date' => Dates::part($a->request_date), 'request_reference' => (string) $a->request_reference,
                'guarantee_bank' => $a->guarantee_bank, 'guarantee_number' => $a->guarantee_number, 'guarantee_amount' => $a->guarantee_amount === null ? null : (float) $a->guarantee_amount,
                'guarantee_end_date' => $a->guarantee_end_date ? Dates::part($a->guarantee_end_date) : null, 'paid_date' => $a->paid_date ? Dates::part($a->paid_date) : null, 'payment_reference' => (string) $a->payment_reference,
            ];
        }
        $penaltyBy = DB::table('contract_decomptes')->whereIn('contract_id', $ids)->where('status', 'VALIDE')->groupBy('contract_id')->selectRaw('contract_id, SUM(penalty_amount) AS total')->pluck('total', 'contract_id');
        $renewBy = [];
        foreach (DB::table('contract_renewals as r')->leftJoin('users as u', 'u.id', '=', 'r.created_by')->whereIn('r.contract_id', $ids)->orderBy('r.renewal_no')
            ->select('r.*', 'u.full_name as created_by_name')->get() as $r) {
            $renewBy[$r->contract_id][] = [
                'id' => (int) $r->id, 'renewal_no' => (int) $r->renewal_no, 'date' => Dates::part($r->date), 'previous_reference' => (string) $r->previous_reference, 'new_reference' => (string) $r->new_reference,
                'added_amount' => (float) $r->added_amount, 'previous_amount' => (float) $r->previous_amount, 'previous_end' => $r->previous_end_date ? Dates::part($r->previous_end_date) : '',
                'period_start' => Dates::part($r->period_start), 'new_end' => Dates::part($r->new_end_date), 'months' => (int) $r->months, 'last_line_no' => (int) $r->last_line_no,
                'reason' => (string) $r->reason, 'created_by' => (string) ($r->created_by_name ?? ''),
            ];
        }
        $amendBy = [];
        foreach (DB::table('contract_amendments as a')->leftJoin('users as u', 'u.id', '=', 'a.created_by')->whereIn('a.contract_id', $ids)->orderBy('a.date')->orderBy('a.id')
            ->select('a.*', 'u.full_name as created_by_name')->get() as $a) {
            $amendBy[$a->contract_id][] = [
                'id' => (int) $a->id, 'date' => Dates::part($a->date), 'added_months' => (int) $a->added_months ?: (int) max(1, round(((int) $a->duration_days) / 30)),
                'previous_end' => $a->previous_end_date ? Dates::part($a->previous_end_date) : '', 'new_end' => $a->new_end_date ? Dates::part($a->new_end_date) : '',
                'reason' => (string) $a->reason, 'created_by' => (string) ($a->created_by_name ?? ''),
            ];
        }
        $guarBy = [];
        foreach (self::guaranteeRows(['contract_ids' => $ids]) as $g) $guarBy[$g['contract_id']] = $g;

        $out = [];
        foreach ($rows as $c) {
            $cid = (int) $c->id;
            $out[] = [
                'id' => $cid, 'number' => (string) $c->reference, 'title' => (string) ($c->title ?? ''), 'phase_id' => (int) $c->phase_id, 'phase' => (string) ($c->phase_name ?? ''),
                'company_id' => (int) $c->company_id, 'company' => (string) ($c->company_name ?? ''), 'category' => (string) ($c->category ?? ''), 'province' => (string) ($c->province ?? ''),
                'work_type_text' => (string) ($c->work_type_text ?? ''), 'signature_date' => $c->signature_date ? Dates::part($c->signature_date) : null,
                'start_date' => $c->start_date ? Dates::part($c->start_date) : null, 'end_date' => $c->end_date ? Dates::part($c->end_date) : null,
                'duration_months' => (int) ($c->duration_months ?? 0), 'amount' => (float) $c->amount, 'initial_rate' => (float) $c->initial_financing_rate,
                'activity' => (string) ($c->activities ?? ''), 'completed' => (bool) ($c->completed ?? false),
                'terminated' => !empty($c->termination_date), 'termination_date' => $c->termination_date ? Dates::part($c->termination_date) : null,
                'termination_reason' => (string) ($c->termination_reason ?? ''),
                'sites' => array_values($sitesBy[$cid] ?? []), 'entries' => $entriesBy[$cid] ?? [], 'payments' => $paymentsBy[$cid] ?? [],
                'amendments' => $amendBy[$cid] ?? [], 'guarantee' => $guarBy[$cid] ?? null,
                'renewal_no' => (int) ($c->renewal_no ?? 0), 'renewed_amount' => (float) ($c->renewed_amount ?? 0), 'renewals' => $renewBy[$cid] ?? [],
                'goods_procedure' => $c->goods_procedure ?? null, 'goods_origin' => $c->goods_origin ?? null, 'payment_schedule' => json_decode((string) ($c->payment_schedule ?? ''), true) ?: [],
                'delay_penalty_rate' => isset($c->delay_penalty_rate) ? (float) $c->delay_penalty_rate : null, 'delay_penalty_cap' => isset($c->delay_penalty_cap) ? (float) $c->delay_penalty_cap : null,
                'unit_id' => isset($c->organizational_unit_id) ? (int) $c->organizational_unit_id : null, 'unit_name' => $c->unit_name ?? null,
                'goods_min_amount' => isset($c->goods_min_amount) ? (float) $c->goods_min_amount : null, 'goods_max_amount' => isset($c->goods_max_amount) ? (float) $c->goods_max_amount : null,
                'advance' => $advanceBy[$cid] ?? null, 'penalties' => (float) ($penaltyBy[$cid] ?? 0),
            ];
        }
        return $out;
    }

    /** Garanties du projet (filtre phase facultatif) ou de certains contrats, avec prolongements/renouvellements. */
    public static function guaranteeRows(array $opts): array
    {
        $q = DB::table('performance_guarantees as g')
            ->join('contracts as c', 'c.id', '=', 'g.contract_id')
            ->leftJoin('companies as co', 'co.id', '=', 'g.company_id')
            ->leftJoin('companies as cc', 'cc.id', '=', 'c.company_id')
            ->select('g.*', 'c.reference as contract_number', DB::raw('c.amount - c.renewed_amount as contract_amount'), 'c.renewal_no as contract_renewal_no', 'co.name as company_name', 'cc.name as contract_company')
            ->orderBy('g.end_date')->orderBy('g.id');
        if (!empty($opts['contract_ids'])) $q->whereIn('g.contract_id', $opts['contract_ids']);
        if (!empty($opts['project_id'])) $q->where('g.project_id', $opts['project_id']);
        if (!empty($opts['phase_id'])) $q->where('c.phase_id', $opts['phase_id']);
        $rows = $q->get();
        if ($rows->isEmpty()) return [];
        $extBy = [];
        foreach (DB::table('guarantee_extensions as e')->leftJoin('users as u', 'u.id', '=', 'e.created_by')->whereIn('e.performance_guarantee_id', $rows->pluck('id')->all())
            ->orderBy('e.start_date')->orderBy('e.id')->select('e.*', 'u.full_name as created_by_name')->get() as $e) {
            $extBy[$e->performance_guarantee_id][] = [
                'id' => (int) $e->id, 'start' => Dates::part($e->start_date), 'end' => Dates::part($e->end_date), 'type' => (string) $e->type,
                'reason' => (string) $e->reason, 'created_at' => (string) $e->created_at, 'created_by' => (string) ($e->created_by_name ?? ''),
            ];
        }
        $out = [];
        foreach ($rows as $g) {
            // Une garantie par contrat : on conserve la plus récente si des doublons existent.
            $out[$g->contract_id] = [
                'id' => (int) $g->id, 'contract_id' => (int) $g->contract_id, 'contract_number' => (string) $g->contract_number, 'company_id' => (int) $g->company_id,
                'company' => (string) ($g->company_name ?: $g->contract_company), 'bank' => (string) $g->bank_name, 'account' => (string) $g->account_number,
                'rate' => (float) $g->rate, 'amount' => (float) $g->amount, 'start' => Dates::part($g->start_date), 'end' => Dates::part($g->end_date),
                'observation' => (string) $g->observation, 'extensions' => $extBy[$g->id] ?? [], 'contract_amount' => (float) $g->contract_amount, 'contract_renewed' => (int) $g->contract_renewal_no > 0,
            ];
        }
        return array_values($out);
    }
}
