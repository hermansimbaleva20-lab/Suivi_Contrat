<?php

namespace Tests\Unit;

use App\Support\StockLedger;
use PHPUnit\Framework\TestCase;

/** Colonne « STOCK » de la fiche de stock : solde courant, mouvements annulés, sorties antidatées. */
class StockLedgerTest extends TestCase
{
    private static function m(int $id, string $date, string $type, float $q, bool $cancelled = false): array
    {
        return ['id' => $id, 'date' => $date, 'type' => $type, 'quantity' => $q, 'cancelled' => $cancelled];
    }

    public function test_solde_courant_comme_sur_la_fiche_papier(): void
    {
        // Approvisionnement de 16 rames puis sorties de 5, 5, 5 et 1 : 11, 6, 1, 0.
        $rows = StockLedger::withBalances([
            self::m(1, '2023-03-01', 'ENTREE', 16),
            self::m(2, '2023-03-01', 'SORTIE', 5),
            self::m(3, '2023-03-01', 'SORTIE', 5),
            self::m(4, '2023-03-06', 'SORTIE', 5),
            self::m(5, '2023-03-14', 'SORTIE', 1),
        ]);
        $this->assertSame([16.0, 11.0, 6.0, 1.0, 0.0], array_column($rows, 'balance'));
    }

    public function test_ordre_chronologique_puis_ordre_de_saisie(): void
    {
        $rows = StockLedger::withBalances([
            self::m(3, '2023-04-12', 'SORTIE', 5),
            self::m(1, '2023-04-01', 'ENTREE', 100),
            self::m(2, '2023-04-11', 'SORTIE', 2),
        ]);
        $this->assertSame([1, 2, 3], array_column($rows, 'id'));
        $this->assertSame([100.0, 98.0, 93.0], array_column($rows, 'balance'));
    }

    public function test_un_mouvement_annule_ne_compte_pas(): void
    {
        $moves = [self::m(1, '2023-04-01', 'ENTREE', 10), self::m(2, '2023-04-02', 'SORTIE', 4, true), self::m(3, '2023-04-03', 'SORTIE', 3)];
        $this->assertSame([10.0, 10.0, 7.0], array_column(StockLedger::withBalances($moves), 'balance'));
        $this->assertSame(7.0, StockLedger::balance($moves));
    }

    public function test_une_sortie_antidatee_qui_rend_le_stock_negatif_est_detectee(): void
    {
        // 10 en stock le 1er, 8 sortis le 10 : une sortie de 5 au 5 rendrait le solde négatif le 10.
        $moves = [self::m(1, '2023-04-01', 'ENTREE', 10), self::m(2, '2023-04-10', 'SORTIE', 8), self::m(9, '2023-04-05', 'SORTIE', 5)];
        $short = StockLedger::firstShortage($moves);
        $this->assertNotNull($short);
        $this->assertSame('2023-04-10', $short['date']);
        $this->assertNull(StockLedger::firstShortage([self::m(1, '2023-04-01', 'ENTREE', 10), self::m(2, '2023-04-10', 'SORTIE', 10)]));
    }

    public function test_quantites_decimales_sans_erreur_d_arrondi(): void
    {
        $moves = [self::m(1, '2023-01-01', 'ENTREE', 0.1), self::m(2, '2023-01-01', 'ENTREE', 0.2), self::m(3, '2023-01-02', 'SORTIE', 0.3)];
        $this->assertSame(0.0, StockLedger::balance($moves));
        $this->assertNull(StockLedger::firstShortage($moves));
    }
}
