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

    /**
     * Niveau d'alerte d'un article (décision du 04/11/2026) : celui saisi sur l'article s'il existe, sinon selon son unité,
     * 1 pour un paquet (Pqt, Pqt/100pces…) et 2 pour une pièce (Pce, Pièce). Les autres unités n'ont pas de niveau par défaut.
     */
    public static function threshold(string $unit, ?float $articleThreshold): ?float
    {
        if ($articleThreshold !== null) return $articleThreshold;
        $u = mb_strtolower(trim($unit));
        if (str_starts_with($u, 'pqt') || str_starts_with($u, 'paquet')) return 1.0;
        if (str_starts_with($u, 'pce') || str_starts_with($u, 'pcs') || str_starts_with($u, 'pièce') || str_starts_with($u, 'piece')) return 2.0;
        return null;
    }

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
