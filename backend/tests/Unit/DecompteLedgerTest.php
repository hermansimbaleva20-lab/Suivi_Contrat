<?php

namespace Tests\Unit;

use App\Support\DecompteLedger;
use PHPUnit\Framework\TestCase;

/** Situation du contrat : retenues d'avance et de garantie, soldes, lignes rejetées ou en attente. */
class DecompteLedgerTest extends TestCase
{
    private static function line(int $no, float $gross, string $status = 'VALIDE', float $adv = 0.2, float $gar = 0.05): array
    {
        return ['id' => $no, 'line_no' => $no, 'gross_amount' => $gross, 'advance_rate' => $adv, 'guarantee_rate' => $gar, 'status' => $status];
    }

    public function test_ligne_du_modele_situation_du_contrat(): void
    {
        // Modèle : contrat 10 933 203,25 USD, avance 20 % ; décompte N°1 de 870 459,95 USD.
        $advance = DecompteLedger::advanceReceived(10933203.25, 0.2);
        $this->assertSame(2186640.65, $advance);
        $r = DecompteLedger::rows(10933203.25, $advance, [self::line(1, 870459.95)])[0];
        $this->assertSame(174091.99, $r['advance']);
        $this->assertSame(43523.0, $r['guarantee']);
        $this->assertSame(652844.96, $r['net']);
        $this->assertSame(8093717.64, $r['contractBalance']);
        $this->assertSame(2012548.66, $r['advanceBalance']);
    }

    public function test_remboursement_plafonne_au_solde_de_l_avance(): void
    {
        $rows = DecompteLedger::rows(1000, 200, [self::line(1, 600), self::line(2, 600)]);
        $this->assertSame(120.0, $rows[0]['advance']);
        $this->assertSame(80.0, $rows[1]['advance']); // reste de l'avance seulement
        $this->assertSame(0.0, $rows[1]['advanceBalance']);
    }

    public function test_ligne_rejetee_ignoree_et_ligne_en_attente_provisoire(): void
    {
        $rows = DecompteLedger::rows(1000, 200, [self::line(1, 100, 'REJETE'), self::line(2, 100, 'EN_ATTENTE'), self::line(3, 100)]);
        $this->assertNull($rows[0]['net']);
        $this->assertSame(75.0, $rows[1]['net']);
        $this->assertSame(650.0, $rows[2]['contractBalance']); // 1000 − 200 − 75 − 75
        $t = DecompteLedger::totals($rows);
        $this->assertSame(1, $t['validated']);
        $this->assertSame(1, $t['pending']);
        $this->assertSame(1, $t['rejected']);
        $this->assertSame(75.0, $t['net']);
    }

    public function test_contrat_de_service_sans_avance(): void
    {
        $r = DecompteLedger::rows(1000, DecompteLedger::advanceReceived(1000, 0), [self::line(1, 400, 'VALIDE', 0)])[0];
        $this->assertSame(0.0, $r['advance']);
        $this->assertSame(20.0, $r['guarantee']);
        $this->assertSame(620.0, $r['contractBalance']);
    }
}
