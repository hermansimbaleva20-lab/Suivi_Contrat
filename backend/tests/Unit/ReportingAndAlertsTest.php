<?php

namespace Tests\Unit;

use App\Support\AlertEngine;
use App\Support\ReportBuilder;
use PHPUnit\Framework\TestCase;

/** Périodes des rapports, comparaison de deux états, séquence d'envoi des alertes. */
class ReportingAndAlertsTest extends TestCase
{
    public function test_bornes_des_periodes(): void
    {
        $this->assertSame(['2026-04-01', '2026-06-30', 'T2 2026'], ReportBuilder::period('TRIMESTRE', 2026, 2));
        $this->assertSame(['2026-02-01', '2026-02-28', 'Février 2026'], ReportBuilder::period('MOIS', 2026, 2));
        $this->assertSame(['2026-01-01', '2026-12-31', 'Année 2026'], ReportBuilder::period('ANNEE', 2026));
        $this->assertSame('Du 05/01/2026 au 20/03/2026', ReportBuilder::period('LIBRE', 2026, 1, '2026-01-05', '2026-03-20')[2]);
    }

    public function test_comparaison_d_un_trimestre_a_l_autre(): void
    {
        $t1 = [['id' => 1, 'number' => 'C1', 'company' => 'A', 'progress' => 40.0, 'paymentRate' => 30.0, 'paid' => 300.0, 'status' => 'Retard']];
        $t2 = [
            ['id' => 1, 'number' => 'C1', 'company' => 'A', 'progress' => 65.5, 'paymentRate' => 50.0, 'paid' => 500.0, 'status' => 'Normal'],
            ['id' => 2, 'number' => 'C2', 'company' => 'B', 'progress' => 10.0, 'paymentRate' => 20.0, 'paid' => 200.0, 'status' => 'Normal'],
        ];
        $c = ReportBuilder::compare($t1, $t2);
        $this->assertSame(25.5, $c[0]['progressDelta']);
        $this->assertSame(200.0, $c[0]['paidDelta']);
        $this->assertSame('Retard', $c[0]['statusA']);
        $this->assertTrue($c[1]['isNew']);
        $this->assertNull($c[1]['progressA']);

        $s = ReportBuilder::summary($t2 + [2 => ['id' => 3, 'number' => 'C3', 'company' => 'C', 'progress' => 0.0, 'paymentRate' => 0.0, 'paid' => 0.0, 'status' => 'Retard', 'amount' => 0.0, 'remaining' => 0.0]]);
        $this->assertSame(3, $s['contracts']);
        $this->assertSame(['Normal' => 2, 'Retard' => 1], $s['statuses']);
    }

    public function test_sequence_d_envoi(): void
    {
        $this->assertTrue(AlertEngine::isDue(0, null, 7, 3, '2026-10-29'));
        $this->assertFalse(AlertEngine::isDue(1, '2026-10-25', 7, 3, '2026-10-29')); // relance dans 7 jours
        $this->assertTrue(AlertEngine::isDue(1, '2026-10-22', 7, 3, '2026-10-29'));
        $this->assertFalse(AlertEngine::isDue(3, '2026-01-01', 7, 3, '2026-10-29')); // plafond atteint
    }
}
