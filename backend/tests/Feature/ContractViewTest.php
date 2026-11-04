<?php

namespace Tests\Feature;

use App\Support\Views;
use Tests\TestCase;

/** Aperçu financier du contrat (TDR §9.1) : montant payé, taux payé et montant restant. */
class ContractViewTest extends TestCase
{
    private function contrat(array $payments, float $initialRate = 0.0): array
    {
        return [
            'id' => 1, 'number' => 'C-1', 'title' => '', 'phase_id' => 1, 'phase' => 'Phase 1', 'company_id' => 1, 'company' => 'E',
            'category' => 'TRAVAUX', 'province' => '', 'work_type_text' => '', 'signature_date' => null, 'start_date' => '2025-01-01',
            'end_date' => '2025-12-31', 'duration_months' => 12, 'amount' => 1000.0, 'renewal_no' => 0, 'renewed_amount' => 0.0, 'initial_rate' => $initialRate, 'activity' => '',
            'completed' => false, 'terminated' => false, 'termination_date' => null, 'termination_reason' => '',
            'sites' => [], 'entries' => [], 'payments' => $payments, 'guarantee' => null,
        ];
    }

    public function test_montant_restant_deduit_avance_et_paiements(): void
    {
        $v = Views::contract($this->contrat([['id' => 1, 'date' => '2025-02-01', 'amount' => 300.0, 'reference' => '', 'observation' => '']], 20.0), false);

        $this->assertSame(500.0, $v['paidIncludingInitial']);
        $this->assertSame(50.0, $v['paymentRate']);
        $this->assertSame(500.0, $v['remainingAmount']);
    }

    public function test_montant_restant_negatif_si_trop_paye(): void
    {
        $v = Views::contract($this->contrat([['id' => 1, 'date' => '2025-02-01', 'amount' => 1200.0, 'reference' => '', 'observation' => '']]), false);

        $this->assertSame(-200.0, $v['remainingAmount']);
    }
}
