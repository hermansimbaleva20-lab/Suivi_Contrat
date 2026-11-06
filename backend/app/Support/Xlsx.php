<?php

namespace App\Support;

// Sans cet import, « new ZipArchive() » cherchait App\Support\ZipArchive : tout export Excel échouait en erreur 500.
use ZipArchive;

/**
 * Générateur XLSX minimal (sans dépendance Composer) : plusieurs feuilles, styles, largeurs, fusions, volets figés.
 * Styles : 0 normal, 1 en-tête, 2 gras, 3 montant, 4 pourcentage, 5 date, 6 texte avec bordure, 7 titre.
 */
class Xlsx
{
    /** Taille maximale d'une partie du classeur lue à l'import, une fois décompressée. */
    public const READ_LIMIT = 20 * 1024 * 1024;

    public const NORMAL = 0, HEADER = 1, BOLD = 2, MONEY = 3, PCT = 4, DATE = 5, TEXT = 6, TITLE = 7;

    public static function col($i)
    {
        $s = '';
        $i++;
        while ($i > 0) {
            $m = ($i - 1) % 26;
            $s = chr(65 + $m) . $s;
            $i = intdiv($i - 1, 26);
        }
        return $s;
    }

    public static function esc($t)
    {
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$t);
        return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function serial($ymd)
    {
        $ymd = substr((string)$ymd, 0, 10);
            if (!\App\Support\Dates::valid($ymd)) return null;
        return (int)round((gmmktime(0, 0, 0, (int)substr($ymd, 5, 2), (int)substr($ymd, 8, 2), (int)substr($ymd, 0, 4)) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400);
    }

    /** Cellule : valeur scalaire, ou ['v' => valeur, 's' => style], ou ['d' => 'Y-m-d', 's' => style]. */
    public static function cellXml($ref, $cell)
    {
        $style = 0;
        if (is_array($cell)) {
            $style = (int)($cell['s'] ?? 0);
            if (isset($cell['d'])) {
                $serial = self::serial($cell['d']);
                if ($serial === null) return '<c r="' . $ref . '" s="' . self::TEXT . '"/>';
                return '<c r="' . $ref . '" s="' . ($style ?: self::DATE) . '"><v>' . $serial . '</v></c>';
            }
            $v = $cell['v'] ?? '';
        } else {
            $v = $cell;
        }
        if ($v === null || $v === '') return $style ? '<c r="' . $ref . '" s="' . $style . '"/>' : '';
        if (is_int($v) || is_float($v)) return '<c r="' . $ref . '" s="' . $style . '"><v>' . (is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : $v) . '</v></c>';
        return '<c r="' . $ref . '" t="inlineStr" s="' . ($style ?: 0) . '"><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
    }

    public static function sheetXml($sheet)
    {
        $freeze = (int)($sheet['freezeRows'] ?? 0);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0">';
        if ($freeze > 0) $xml .= '<pane ySplit="' . $freeze . '" topLeftCell="A' . ($freeze + 1) . '" activePane="bottomLeft" state="frozen"/>';
        $xml .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if (!empty($sheet['widths'])) {
            $xml .= '<cols>';
            foreach ($sheet['widths'] as $i => $w) $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($sheet['rows'] as $r => $cells) {
            $height = $sheet['heights'][$r] ?? null;
            $xml .= '<row r="' . ($r + 1) . '"' . ($height ? ' ht="' . (float)$height . '" customHeight="1"' : '') . '>';
            foreach ($cells as $c => $cell) $xml .= self::cellXml(self::col($c) . ($r + 1), $cell);
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if (!empty($sheet['merges'])) {
            $xml .= '<mergeCells count="' . count($sheet['merges']) . '">';
            foreach ($sheet['merges'] as $m) $xml .= '<mergeCell ref="' . $m . '"/>';
            $xml .= '</mergeCells>';
        }
        return $xml . '</worksheet>';
    }

    public static function stylesXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="0.00&quot;%&quot;"/></numFmts>' .
        '<fonts count="4">' .
        '<font><sz val="11"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
        '<font><b/><sz val="14"/><color rgb="FF123B5D"/><name val="Calibri"/></font>' .
        '</fonts>' .
        '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>' .
        '<fill><patternFill patternType="solid"><fgColor rgb="FF087A3F"/><bgColor indexed="64"/></patternFill></fill></fills>' .
        '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' .
        '<border><left style="thin"><color rgb="FFD9E3EA"/></left><right style="thin"><color rgb="FFD9E3EA"/></right><top style="thin"><color rgb="FFD9E3EA"/></top><bottom style="thin"><color rgb="FFD9E3EA"/></bottom><diagonal/></border></borders>' .
        '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
        '<cellXfs count="8">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
        '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>' .
        '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>' .
        '<xf numFmtId="14" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' .
        '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
        '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    /**
     * Lecture minimale de la première feuille d'un classeur .xlsx : tableau de lignes, chaque ligne étant un tableau de
     * valeurs texte indexé par colonne (0 = A). Les dates restent au format numérique d'Excel.
     */
    public static function read(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('Fichier Excel illisible.');
        // Un classeur de 2 Mo peut cacher des centaines de Mo une fois décompressé : la taille annoncée est vérifiée avant lecture.
        $entry = function (string $name) use ($zip) {
            $stat = $zip->statName($name);
            if ($stat === false) return false;
            if ($stat['size'] > self::READ_LIMIT) throw new \RuntimeException('Fichier Excel trop volumineux une fois décompressé.');
            return $zip->getFromName($name, self::READ_LIMIT);
        };
        $shared = [];
        if (($xml = $entry('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET)->si as $si) {
                $text = isset($si->t) ? (string) $si->t : '';
                foreach ($si->r ?? [] as $run) $text .= (string) $run->t;
                $shared[] = $text;
            }
        }
        $sheet = $entry('xl/worksheets/sheet1.xml');
        for ($i = 0; $sheet === false && $i < min($zip->numFiles, 200); $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) $sheet = $entry($name);
        }
        $zip->close();
        if ($sheet === false) throw new \RuntimeException('Le classeur ne contient aucune feuille.');

        $rows = [];
        foreach (simplexml_load_string($sheet, 'SimpleXMLElement', LIBXML_NONET)->sheetData->row ?? [] as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                $col = 0;
                foreach (str_split($m[1] ?? 'A') as $ch) $col = $col * 26 + (ord($ch) - 64);
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) ($c->is->t ?? ''),
                    default => (string) $c->v,
                };
                $cells[$col - 1] = $value;
            }
            if ($cells) {
                $max = max(array_keys($cells));
                $rows[] = array_map(fn ($k) => $cells[$k] ?? '', range(0, $max));
            } else {
                $rows[] = [];
            }
        }
        return $rows;
    }

    /** Construit le classeur et retourne son contenu binaire. $sheets = [['name'=>, 'rows'=>[[...]], 'widths'=>[], 'merges'=>[], 'freezeRows'=>n], ...] */
    public static function build($sheets)
    {
        if (!class_exists('ZipArchive')) abort(500, "L'extension PHP « zip » est nécessaire pour l'export Excel : activez-la dans PHP.");
        $tmp = tempnam(sys_get_temp_dir(), 'prx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) abort(500, "Impossible de créer le fichier Excel.");

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $name = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $sheet['name']), 0, 31);
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . self::esc($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', self::sheetXml($sheet));
        }
        $ct .= '</Types>';
        $wb .= '</sheets></workbook>';
        $rels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->close();
        $bin = file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }
}
