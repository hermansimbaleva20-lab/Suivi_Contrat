<?php

namespace Tests\Unit;

use App\Support\Cautions;
use PHPUnit\Framework\TestCase;

/** Tableaux « Caution d'avance de démarrage » et « Garantie de bonne exécution » (décision du 22/11/2026). */
class CautionsTest extends TestCase
{
    private static function contract(array $over = []): array
    {
        return $over + [
            'id' => 1, 'number' => '144', 'company' => 'SERRU TOP', 'amount' => 195842.32, 'acquisition_mode' => 'AON',
            'start_date' => '2023-12-30', 'end_date' => '2025-09-15', 'initial_rate' => 20.0, 'advance' => null,
            'advance_guarantee' => null, 'guarantee' => null,
        ];
    }

    private static function guarantee(array $over = []): array
    {
        return $over + [
            'id' => 7, 'account' => 'N° 00590/TMB/KIN/2023', 'bank' => 'TMB', 'start' => '2026-03-30', 'end' => '2026-06-30',
            'observation' => '', 'extensions' => [], 'contract_renewed' => false, 'rate' => 5.0, 'amount' => 1000.0,
        ];
    }

    public function test_validite_en_jours_comme_le_tableau_du_projet(): void
    {
        $this->assertSame(121, Cautions::validity('2024-03-20', '2024-07-19'));
        $this->assertSame(92, Cautions::validity('2026-03-30', '2026-06-30'));
        $this->assertSame(365, Cautions::validity('2026-03-16', '2027-03-16'));
        $this->assertNull(Cautions::validity(null, '2026-03-16'));
    }

    public function test_mode_d_acquisition(): void
    {
        $this->assertSame('AON', Cautions::modeFromInput('AON', '', $err));
        $this->assertNull($err);
        $this->assertSame('Gré à gré', Cautions::modeFromInput('AUTRE', ' Gré à gré ', $err));
        $this->assertNull(Cautions::modeFromInput('AUTRE', '', $err));
        $this->assertSame('Précisez le mode d’acquisition.', $err);
        $this->assertNull(Cautions::modeFromInput('', '', $err));
        $this->assertNotNull($err);
        $this->assertNull(Cautions::modeFromInput('XYZ', '', $err));
        $this->assertNotNull($err);
        $this->assertSame(['AOI', ''], Cautions::modeInput('AOI'));
        $this->assertSame(['AUTRE', 'Gré à gré'], Cautions::modeInput('Gré à gré'));
        $this->assertSame('Appel d’offres national', Cautions::modeLabel('AON'));
        $this->assertSame('Gré à gré', Cautions::modeLabel('Gré à gré'));
    }

    public function test_caution_d_avance_saisie_expiree_ou_absente(): void
    {
        $avec = self::contract(['advance_guarantee' => ['reference' => 'N° 12/24', 'bank' => 'LCB BANK', 'issue_date' => '2024-03-20', 'end_date' => '2024-07-19', 'observation' => '']]);
        $sans = self::contract(['id' => 2, 'number' => '145', 'start_date' => '2024-01-08']);
        $sansAvance = self::contract(['id' => 3, 'number' => '146', 'initial_rate' => 0.0]);
        $rows = Cautions::advanceRows([$sans, $sansAvance, $avec], '2026-11-22');
        $this->assertCount(2, $rows, 'Un contrat sans avance n’a pas de ligne.');
        // Les lignes suivent la date de début d'exécution.
        $this->assertSame('144', $rows[0]['contractNumber']);
        $this->assertSame([121, true, 'Caution expirée'], [$rows[0]['validity'], $rows[0]['expired'], $rows[0]['observation']]);
        $this->assertSame(['SERRU TOP', 'AON', '2023-12-30', '2025-09-15'], [$rows[0]['holder'], $rows[0]['mode'], $rows[0]['start'], $rows[0]['end']]);
        $this->assertSame([true, false, 'Caution non renseignée'], [$rows[1]['missing'], $rows[1]['expired'], $rows[1]['observation']]);
        // Avant son expiration, la caution n'est pas signalée ; une observation saisie reste telle quelle.
        $avec['advance_guarantee']['observation'] = 'Correspondance adressée';
        $row = Cautions::advanceRows([$avec], '2024-05-01')[0];
        $this->assertSame([false, 'Correspondance adressée'], [$row['expired'], $row['observation']]);
    }

    public function test_avance_sur_demande_d_un_marche_de_biens(): void
    {
        $c = self::contract(['initial_rate' => 0.0, 'advance' => ['guarantee_number' => 'GC1089625000042', 'guarantee_bank' => 'BANK OF CHINA', 'request_date' => '2025-02-24', 'guarantee_end_date' => '2027-03-24']]);
        $row = Cautions::advanceRows([$c], '2026-11-22')[0];
        $this->assertSame(['GC1089625000042', 'BANK OF CHINA', '2025-02-24', '2027-03-24', 758, false], [$row['reference'], $row['bank'], $row['date'], $row['expiry'], $row['validity'], $row['expired']]);
        // Avec la date de la caution (23/11/2026), c'est elle qui ouvre la validité, pas la date de la demande.
        $c['advance']['guarantee_issue_date'] = '2025-02-18';
        $row = Cautions::advanceRows([$c], '2026-11-22')[0];
        $this->assertSame(['2025-02-18', 764], [$row['date'], $row['validity']]);
    }

    public function test_garantie_expiree_et_correspondance_adressee(): void
    {
        $c = self::contract(['guarantee' => self::guarantee()]);
        $row = Cautions::guaranteeRows([$c], '2026-11-22')[0];
        $this->assertSame([92, true, 'Garantie expirée'], [$row['validity'], $row['expired'], $row['observation']]);
        $row = Cautions::guaranteeRows([$c], '2026-11-22', [7 => true])[0];
        $this->assertSame('Garantie expirée, correspondance adressée', $row['observation']);
        // Prolongée : l'expiration est celle de la période en cours à la date d'arrêt.
        $prolongee = self::contract(['guarantee' => self::guarantee(['extensions' => [['id' => 1, 'start' => '2026-07-01', 'end' => '2026-12-31', 'type' => 'PROLONGATION', 'reason' => '', 'created_at' => '', 'created_by' => '']]])]);
        $row = Cautions::guaranteeRows([$prolongee], '2026-11-22')[0];
        $this->assertSame(['2026-12-31', false, ''], [$row['expiry'], $row['expired'], $row['observation']]);
        // Contrat renouvelé : la garantie n'est plus exigée, jamais signalée expirée.
        $renouvele = self::contract(['guarantee' => self::guarantee(['contract_renewed' => true])]);
        $row = Cautions::guaranteeRows([$renouvele], '2026-11-22')[0];
        $this->assertSame([false, 'Non exigée (contrat renouvelé)'], [$row['expired'], $row['observation']]);
        $this->assertSame([], Cautions::guaranteeRows([self::contract()], '2026-11-22'), 'Sans garantie, pas de ligne.');
    }
}
