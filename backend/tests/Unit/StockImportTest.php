<?php

namespace Tests\Unit;

use App\Support\StockImport;
use PHPUnit\Framework\TestCase;

/** Import du catalogue « prix unitaires évalués » : en-têtes libres, séparateurs, encodage, lignes à ignorer. */
class StockImportTest extends TestCase
{
    public function test_entetes_du_modele_papier_dans_n_importe_quel_ordre(): void
    {
        $rows = [
            ['PRIX UNITAIRES EVALUES'],
            [],
            ['N°', 'ARTICLE', 'UNITE', 'QUANTITE', 'P U /$', 'PT /$'],
            ['1', 'Agrafeuse (Grand Format) : Qualité Supérieure', 'Pqt', '1', '12', ''],
            ['2', 'Adaptateur USB/ RJ45 : 3,0', 'Pqt', '1', '20', ''],
            ['', '', '', '', '', ''],
            ['', 'TOTAL', '', '', '', '32'],
            ['8', 'Anneaux de 10mm (Pqt de 100 pièces)', 'Pqt/100pces', '', '12,30', ''],
        ];
        $out = StockImport::parse($rows);
        $this->assertCount(3, $out);
        $this->assertSame(['line' => 4, 'number' => 1, 'designation' => 'Agrafeuse (Grand Format) : Qualité Supérieure', 'unit' => 'Pqt', 'quantity' => 1.0, 'price' => 12.0, 'errors' => []], $out[0]);
        $this->assertSame(12.3, $out[2]['price']);
        $this->assertNull($out[2]['quantity']);

        $other = StockImport::parse([['Désignation', 'PU', 'Unités'], ['Stylo bleu', '0,5', 'Boîte/50']]);
        $this->assertSame('Boîte/50', $other[0]['unit']);
        $this->assertSame(0.5, $other[0]['price']);
    }

    public function test_csv_point_virgule_et_encodage_windows(): void
    {
        $csv = mb_convert_encoding("N°;Article;Unité;Quantité;PU \$\n1;Brosse de toilette;Pce;3;8\n2;Cahier cartonné;Pce;;12\n", 'Windows-1252', 'UTF-8');
        $out = StockImport::parse(StockImport::readCsv($csv));
        $this->assertSame('Brosse de toilette', $out[0]['designation']);
        $this->assertSame('Cahier cartonné', $out[1]['designation']);
        $this->assertSame(3.0, $out[0]['quantity']);

        $comma = StockImport::parse(StockImport::readCsv("\xEF\xBB\xBFArticle,Unité,PU\n\"Rame papier A4\",Rame,6.5\n"));
        $this->assertSame(6.5, $comma[0]['price']);
    }

    public function test_lignes_en_erreur_signalees(): void
    {
        $out = StockImport::parse([['Article', 'Unité', 'Quantité', 'PU'], ['Stylo', '', '-2', 'abc']]);
        $this->assertSame(['Unité manquante', 'PU invalide', 'Quantité invalide'], $out[0]['errors']);
    }

    public function test_fichier_sans_colonnes_obligatoires(): void
    {
        $this->expectException(\RuntimeException::class);
        StockImport::parse([['Nom', 'Prix'], ['Stylo', '1']]);
    }
}
