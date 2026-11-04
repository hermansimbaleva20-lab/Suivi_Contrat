<?php

namespace App\Support;

/** Mise en forme JSON (camelCase) des contrats, sites, garanties et utilisateurs. */
class Views
{
    private static function advance(?array $a): ?array
    {
        if (!$a) return null;
        return [
            'id' => $a['id'], 'status' => $a['status'], 'rate' => $a['rate'], 'amount' => $a['amount'], 'requestDate' => $a['request_date'], 'requestReference' => $a['request_reference'],
            'guaranteeBank' => $a['guarantee_bank'], 'guaranteeNumber' => $a['guarantee_number'], 'guaranteeAmount' => $a['guarantee_amount'], 'guaranteeEndDate' => $a['guarantee_end_date'],
            'paidDate' => $a['paid_date'], 'paymentReference' => $a['payment_reference'],
        ];
    }

    public static function site(array $s): array
    {
        return [
            'id' => $s['id'], 'name' => $s['name'], 'province' => $s['province'], 'workType' => $s['work_type'],
            'description' => $s['description'], 'quantity' => $s['quantity'], 'progress' => Metrics::pct(Metrics::siteProgress($s)),
        ];
    }

    public static function startupAdvance(array $c): ?array
    {
        $best = null;
        foreach ($c['payments'] as $p) {
            if (preg_match('/\bavance\b/iu', ($p['reference'] ?? '') . ' ' . ($p['observation'] ?? ''))) {
                if ($best === null || $p['date'] < $best['date']) $best = $p;
            }
        }
        return $best;
    }

    public static function contract(array $c, bool $withSites = true): array
    {
        $today = Dates::today();
        $progress = Metrics::progress($c);
        $elapsed = Metrics::elapsed($c, $today);
        $initial = Metrics::initialFinancing($c);
        $paid = Metrics::totalPaid($c);
        $latest = Metrics::latestEntry($c);
        $v = [
            'id' => $c['id'], 'number' => $c['number'], 'title' => $c['title'], 'phaseId' => $c['phase_id'], 'phase' => $c['phase'],
            'companyId' => $c['company_id'], 'company' => $c['company'], 'category' => $c['category'], 'province' => $c['province'],
            'workTypeText' => $c['work_type_text'], 'signatureDate' => $c['signature_date'], 'startDate' => $c['start_date'], 'endDate' => $c['end_date'],
            'durationMonths' => $c['duration_months'], 'amount' => $c['amount'], 'initialFinancingRate' => $c['initial_rate'], 'initialFinancing' => $initial,
            'activity' => $c['activity'], 'completed' => $c['completed'], 'isTerminated' => $c['terminated'], 'terminationDate' => $c['termination_date'],
            'terminationReason' => $c['termination_reason'], 'status' => Metrics::status($c), 'progress' => Metrics::pct($progress), 'elapsed' => Metrics::pct($elapsed),
            'gap' => Metrics::pct($progress - $elapsed), 'totalPaid' => $paid, 'paidIncludingInitial' => $initial + $paid, 'remainingAmount' => round((float) $c['amount'] - $initial - $paid - (float) ($c['penalties'] ?? 0), 2), 'paymentRate' => Metrics::pct(Metrics::paymentPercent($c)),
            'daysRemaining' => $c['end_date'] ? Dates::daysBetween($today, $c['end_date']) : null,
            'latestRealization' => $latest ? $latest['realization'] : '', 'latestObservation' => $latest ? $latest['observation'] : '',
            'sitesCount' => count($c['sites']), 'renewalNo' => $c['renewal_no'], 'renewedAmount' => $c['renewed_amount'],
            // Numéro proposé au prochain renouvellement : 144 → 144/R1, 144/R1 → 144/R2.
            'nextRenewalNumber' => preg_replace('~/R\d+$~', '', $c['number']) . '/R' . ($c['renewal_no'] + 1),
            'canRenew' => GoodsProcedure::renewable((string) $c['category'], $c['goods_procedure'] ?? null),
            'goodsProcedure' => $c['goods_procedure'] ?? null, 'goodsProcedureLabel' => GoodsProcedure::label($c['goods_procedure'] ?? null),
            'goodsOrigin' => $c['goods_origin'] ?? null, 'goodsOriginLabel' => GoodsProcedure::originLabel($c['goods_origin'] ?? null),
            'paymentSchedule' => $c['payment_schedule'] ?? [], 'delayPenaltyRate' => $c['delay_penalty_rate'] ?? null, 'delayPenaltyCap' => $c['delay_penalty_cap'] ?? null, 'penalties' => (float) ($c['penalties'] ?? 0),
            // Avance sur demande : montant prévu, et demande en cours ou payée.
            'advanceOnRequest' => GoodsProcedure::governs($c['goods_procedure'] ?? null), 'advanceDue' => round((float) $c['amount'] * (float) $c['initial_rate'] / 100, 2), 'advanceRequest' => self::advance($c['advance'] ?? null),
        ];
        if ($withSites) {
            $v['sites'] = array_map([self::class, 'site'], $c['sites']);
            $names = [];
            foreach ($c['sites'] as $s) if (trim($s['name']) !== '') $names[] = $s['name'];
            $v['siteNames'] = array_values(array_unique($names));
            $provinces = [];
            foreach ($c['sites'] as $s) if (trim($s['province']) !== '') $provinces[] = $s['province'];
            $v['provinces'] = array_values(array_unique($provinces ?: array_filter([$c['province']])));
        }
        return $v;
    }

    public static function extension(array $e): array
    {
        return [
            'id' => $e['id'], 'kind' => $e['type'] === 'RENOUVELLEMENT' ? 'Renouvellement' : 'Prolongation', 'isRenewal' => $e['type'] === 'RENOUVELLEMENT',
            'startDate' => $e['start'], 'endDate' => $e['end'], 'reason' => $e['reason'], 'createdAt' => $e['created_at'], 'createdBy' => $e['created_by'],
        ];
    }

    public static function guarantee(array $g): array
    {
        $exts = $g['extensions'];
        usort($exts, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']) ?: ($b['id'] - $a['id']));
        return array_merge([
            'id' => $g['id'], 'contractId' => $g['contract_id'], 'contractNumber' => $g['contract_number'] ?? '', 'companyId' => $g['company_id'], 'company' => $g['company'],
            'bank' => $g['bank'], 'account' => $g['account'], 'rate' => $g['rate'], 'amount' => $g['amount'], 'startDate' => $g['start'], 'endDate' => $g['end'],
            'observation' => $g['observation'], 'extensionsCount' => Metrics::extCount($g, 'PROLONGATION'), 'renewalsCount' => Metrics::extCount($g, 'RENOUVELLEMENT'),
            'extensions' => array_map([self::class, 'extension'], $exts), 'contractRenewed' => !empty($g['contract_renewed']),
        ], Metrics::guaranteeFlags($g), !empty($g['contract_renewed'])
            // Contrat renouvelé : la garantie n'est plus exigée, elle ne déclenche plus d'alerte.
            ? ['needsAttention' => false, 'isExpired' => false, 'isExpiringSoon' => false, 'statusLabel' => 'Non exigée', 'statusCss' => 'termine'] : []);
    }

    public static function user($u): array
    {
        $perms = [];
        foreach (['can_manage_settings', 'can_manage_users', 'can_delete_contracts', 'can_manage_contracts', 'can_manage_companies', 'can_manage_progress', 'can_manage_payments', 'can_view_reports', 'can_view_alerts_contracts', 'can_view_alerts_guarantees', 'can_view_alerts_logistics', 'can_view_situation', 'can_view_archives', 'can_manage_guarantees', 'can_view_stock', 'can_manage_stock', 'can_view_fleet', 'can_manage_fleet', 'can_authorize_fleet', 'can_validate_decomptes'] as $k) {
            $perms[lcfirst(str_replace('_', '', ucwords($k, '_')))] = Ctx::can($k, $u);
        }
        return [
            'id' => $u->id, 'name' => $u->full_name ?: $u->name, 'email' => $u->email, 'title' => Ctx::title($u), 'position' => (string) $u->project_position,
            'role' => $u->role, 'isSuperAdmin' => (bool) $u->is_super_admin, 'isAdmin' => Ctx::isAdmin($u), 'projectId' => (int) $u->project_id, 'permissions' => $perms,
        ] + self::assignment($u);
    }

    /** Affectation organisationnelle (TDR §5) ; vide pour les comptes non encore affectés. */
    public static function assignment($u): array
    {
        $unit = $u->organizational_unit_id ? $u->organizationalUnit : null;
        $position = $u->position_id ? $u->position : null;
        return [
            'unit' => $unit ? ['id' => $unit->id, 'type' => $unit->type, 'name' => $unit->name, 'code' => $unit->code] : null,
            'positionId' => $position ? $position->id : null,
            'positionName' => $position ? $position->name : (string) $u->project_position,
            'hasConsolidatedView' => Ctx::readableUnitIds($u) === null,
            'canViewPools' => (bool) $u->can_view_pools,
        ];
    }

    public static function paginate(int $total, int $page, int $size): array
    {
        $pages = max(1, (int) ceil($total / max(1, $size)));
        $page = max(1, min($page, $pages));
        return [$page, $pages, ($page - 1) * $size];
    }
}
