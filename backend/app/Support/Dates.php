<?php

namespace App\Support;

use Carbon\Carbon;

/** Outils de dates au format Y-m-d (comparaison lexicale), identiques à ceux de l'application C#. */
class Dates
{
    public static function today(): string
    {
        return Carbon::now(config('app.timezone'))->toDateString();
    }

    public static function part($value): string
    {
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
        return substr((string) $value, 0, 10);
    }

    public static function valid($value): bool
    {
        $s = self::part($value);
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4));
    }

    public static function daysBetween(string $from, string $to): int
    {
        $a = gmmktime(0, 0, 0, (int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4));
        $b = gmmktime(0, 0, 0, (int) substr($to, 5, 2), (int) substr($to, 8, 2), (int) substr($to, 0, 4));
        return (int) round(($b - $a) / 86400);
    }

    public static function addDays(string $ymd, int $n): string
    {
        return gmdate('Y-m-d', gmmktime(0, 0, 0, (int) substr($ymd, 5, 2), (int) substr($ymd, 8, 2) + $n, (int) substr($ymd, 0, 4)));
    }

    /** Comme DateTime.AddMonths de .NET : le jour est ramené à la fin du mois si nécessaire (31 janv. + 1 mois = 28/29 févr.). */
    public static function addMonths(string $ymd, int $n): string
    {
        $y = (int) substr($ymd, 0, 4);
        $m = (int) substr($ymd, 5, 2);
        $d = (int) substr($ymd, 8, 2);
        $total = $y * 12 + ($m - 1) + $n;
        $ny = (int) floor($total / 12);
        $nm = $total - $ny * 12 + 1;
        $dim = (int) gmdate('t', gmmktime(0, 0, 0, $nm, 1, $ny));
        return sprintf('%04d-%02d-%02d', $ny, $nm, min($d, $dim));
    }

    public static function fr(?string $ymd): string
    {
        $ymd = self::part((string) $ymd);
        return $ymd === '' ? '' : substr($ymd, 8, 2) . '/' . substr($ymd, 5, 2) . '/' . substr($ymd, 0, 4);
    }

    /** Lecture tolérante d'un montant : 1500,75 / 1500.75 / 1 500,75 / 1.500,75 / 1,500.75 → 1500.75 */
    public static function decimal($raw): ?float
    {
        if ($raw === null) return null;
        if (is_int($raw) || is_float($raw)) return (float) $raw;
        $t = preg_replace('/[\s\x{00A0}\x{202F}\'’]/u', '', trim((string) $raw));
        if ($t === '' || $t === null) return null;
        $c = strrpos($t, ',');
        $d = strrpos($t, '.');
        if ($c !== false && $d !== false) {
            $t = ($c > $d) ? str_replace(',', '.', str_replace('.', '', $t)) : str_replace(',', '', $t);
        } elseif ($c !== false) {
            $t = (substr_count($t, ',') > 1) ? str_replace(',', '', $t) : str_replace(',', '.', $t);
        } elseif ($d !== false && substr_count($t, '.') > 1) {
            $t = str_replace('.', '', $t);
        }
        if (!preg_match('/^-?\d*\.?\d+$/', $t) && !preg_match('/^-?\d+\.$/', $t)) return null;
        return (float) $t;
    }
}
