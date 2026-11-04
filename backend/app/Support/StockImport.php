<?php

namespace App\Support;

/**
 * Import du catalogue « prix unitaires évalués » depuis un fichier de l'utilisateur (.xlsx ou .csv) :
 * colonnes N°, ARTICLE (ou désignation), UNITÉ, QUANTITÉ, PU $ (la colonne PT est recalculée, donc ignorée).
 * La ligne d'en-tête est cherchée dans les 15 premières lignes ; l'ordre des colonnes est libre.
 */
class StockImport
{
    public const MAX_ROWS = 2000;

    /** Lignes brutes d'un fichier CSV : séparateur « ; » ou « , » détecté, encodage Windows-1252 converti en UTF-8. */
    public static function readCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        $first = strtok($content, "\n") ?: '';
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) as $line) $rows[] = str_getcsv($line, $sep, '"', '');
        return $rows;
    }

    private static function key(string $header): string
    {
        $h = mb_strtolower(trim($header));
        $h = strtr($h, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', '°' => 'o']);
        return preg_replace('/[^a-z0-9]/', '', $h);
    }

    /** Colonne de chaque champ d'après l'en-tête ; null si l'en-tête n'a pas de colonne « article ». */
    public static function mapHeader(array $row): ?array
    {
        $map = [];
        foreach ($row as $i => $cell) {
            $k = self::key((string) $cell);
            if ($k === '') continue;
            if (!isset($map['designation']) && (str_contains($k, 'article') || str_contains($k, 'designation') || str_contains($k, 'fourniture') || str_contains($k, 'articule'))) $map['designation'] = $i;
            elseif (!isset($map['number']) && in_array($k, ['n', 'no', 'num', 'numero', 'nfiche', 'nofiche'], true)) $map['number'] = $i;
            elseif (!isset($map['unit']) && (str_starts_with($k, 'unite') || $k === 'unit' || $k === 'unites')) $map['unit'] = $i;
            elseif (!isset($map['quantity']) && (str_starts_with($k, 'quantit') || str_starts_with($k, 'qte'))) $map['quantity'] = $i;
            elseif (!isset($map['price']) && (str_starts_with($k, 'pu') || str_contains($k, 'prixunitaire'))) $map['price'] = $i;
        }
        return isset($map['designation']) ? $map : null;
    }

    /**
     * Lignes exploitables : [line, number, designation, unit, quantity, price, errors[]].
     * Les lignes sans désignation (vides, totaux) sont ignorées.
     */
    public static function parse(array $rows): array
    {
        $map = null;
        $start = 0;
        foreach (array_slice($rows, 0, 15) as $i => $row) {
            if ($map = self::mapHeader($row)) { $start = $i + 1; break; }
        }
        if (!$map) throw new \RuntimeException('Colonne « Article » introuvable : la première ligne du tableau doit contenir les intitulés N°, Article, Unité, Quantité, PU $.');
        if (!isset($map['unit']) || !isset($map['price'])) throw new \RuntimeException('Les colonnes « Unité » et « PU $ » sont obligatoires.');

        $out = [];
        $cell = fn ($row, $f) => isset($map[$f]) ? trim((string) ($row[$map[$f]] ?? '')) : '';
        foreach (array_slice($rows, $start, null, true) as $i => $row) {
            $designation = preg_replace('/\s+/u', ' ', $cell($row, 'designation'));
            if ($designation === '' || preg_match('/^(total|sous-total)\b/iu', $designation)) continue;
            if (count($out) >= self::MAX_ROWS) throw new \RuntimeException('Le fichier dépasse ' . self::MAX_ROWS . ' articles : découpez-le.');
            $errors = [];
            $unit = $cell($row, 'unit');
            if ($unit === '') $errors[] = 'Unité manquante';
            $price = Dates::decimal($cell($row, 'price'));
            if ($price === null || $price < 0) $errors[] = 'PU invalide';
            $qtyRaw = $cell($row, 'quantity');
            $qty = $qtyRaw === '' ? null : Dates::decimal($qtyRaw);
            if ($qtyRaw !== '' && ($qty === null || $qty < 0)) $errors[] = 'Quantité invalide';
            $numRaw = $cell($row, 'number');
            $number = ctype_digit($numRaw) && (int) $numRaw > 0 ? (int) $numRaw : null;
            $out[] = [
                'line' => $i + 1, 'number' => $number, 'designation' => mb_substr($designation, 0, 255), 'unit' => mb_substr($unit, 0, 40),
                'quantity' => $qty, 'price' => $price === null ? null : round($price, 2), 'errors' => $errors,
            ];
        }
        return $out;
    }
}
