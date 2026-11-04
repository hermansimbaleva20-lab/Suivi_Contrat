<?php

namespace Tests\Unit;

use App\Support\Dates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DatesTest extends TestCase
{
    public static function montants(): array
    {
        return [
            'virgule' => ['1500,75', 1500.75],
            'point' => ['1500.75', 1500.75],
            'espaces + virgule' => ['1 500,75', 1500.75],
            'milliers point, décimale virgule' => ['1.500,75', 1500.75],
            'milliers virgule, décimale point' => ['1,500.75', 1500.75],
            'espace insécable' => ["1\u{00A0}500", 1500.0],
            'plusieurs points de milliers' => ['1.500.000', 1500000.0],
            'entier' => [42, 42.0],
            'négatif' => ['-12,5', -12.5],
        ];
    }

    #[DataProvider('montants')]
    public function test_decimal_accepte_les_formats_de_saisie(mixed $saisie, float $attendu): void
    {
        $this->assertSame($attendu, Dates::decimal($saisie));
    }

    public function test_decimal_rejette_les_saisies_invalides(): void
    {
        $this->assertNull(Dates::decimal(null));
        $this->assertNull(Dates::decimal(''));
        $this->assertNull(Dates::decimal('abc'));
        $this->assertNull(Dates::decimal('12a'));
    }

    public function test_add_months_ramene_le_jour_a_la_fin_du_mois_comme_dotnet(): void
    {
        $this->assertSame('2024-02-29', Dates::addMonths('2024-01-31', 1));
        $this->assertSame('2025-02-28', Dates::addMonths('2025-01-31', 1));
        $this->assertSame('2026-01-15', Dates::addMonths('2025-11-15', 2));
        $this->assertSame('2024-12-31', Dates::addMonths('2025-01-31', -1));
    }

    public function test_jours_entre_deux_dates_et_ajout_de_jours(): void
    {
        $this->assertSame(366, Dates::daysBetween('2024-01-01', '2025-01-01'));
        $this->assertSame(-1, Dates::daysBetween('2025-01-02', '2025-01-01'));
        $this->assertSame('2025-03-01', Dates::addDays('2025-02-28', 1));
    }

    public function test_validation_et_format_francais(): void
    {
        $this->assertTrue(Dates::valid('2024-02-29'));
        $this->assertFalse(Dates::valid('2025-02-29'));
        $this->assertFalse(Dates::valid('29/02/2024'));
        $this->assertSame('05/03/2025', Dates::fr('2025-03-05 10:00:00'));
        $this->assertSame('', Dates::fr(null));
    }
}
