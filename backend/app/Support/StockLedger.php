<?php

namespace App\Support;

/**
 * Calcul de la colonne « STOCK » de la fiche de stock : solde courant après chaque mouvement, dans l'ordre chronologique
 * (date, puis ordre de saisie). Fonctions pures sur des tableaux, testées sans démarrer Laravel.
 * Un mouvement : ['id' => int, 'date' => 'Y-m-d', 'type' => 'ENTREE'|'SORTIE', 'quantity' => float, 'cancelled' => bool].
 */
class StockLedger
{
    public const ENTREE = 'ENTREE';
    public const SORTIE = 'SORTIE';

    public static function sort(array $moves): array
    {
        usort($moves, fn ($a, $b) => strcmp($a['date'], $b['date']) ?: ($a['id'] <=> $b['id']));
        return $moves;
    }

    public static function signed(array $m): float
    {
        return $m['type'] === self::ENTREE ? (float) $m['quantity'] : -(float) $m['quantity'];
    }

    /** Ajoute 'balance' à chaque mouvement ; les mouvements annulés gardent le solde précédent. */
    public static function withBalances(array $moves, float $opening = 0.0): array
    {
        $balance = $opening;
        $out = [];
        foreach (self::sort($moves) as $m) {
            if (empty($m['cancelled'])) $balance = round($balance + self::signed($m), 2);
            $m['balance'] = $balance;
            $out[] = $m;
        }
        return $out;
    }

    public static function balance(array $moves): float
    {
        $total = 0.0;
        foreach ($moves as $m) if (empty($m['cancelled'])) $total += self::signed($m);
        return round($total, 2);
    }

    /**
     * Premier mouvement après lequel le stock deviendrait négatif, ou null. Sert à refuser une sortie (même antidatée)
     * ou l'annulation d'une entrée qui ferait sortir plus que ce qui était disponible à une date donnée.
     */
    public static function firstShortage(array $moves): ?array
    {
        foreach (self::withBalances($moves) as $m) {
            if ($m['balance'] < 0) return $m;
        }
        return null;
    }
}
