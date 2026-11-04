<?php

namespace Tests\Unit;

use App\Support\AlertTemplates;
use App\Support\GuaranteeAlerts;
use PHPUnit\Framework\TestCase;

/** Relances des garanties : paliers selon la part consommée, jours d'envoi de la semaine, période en cours, message. */
class GuaranteeAlertsTest extends TestCase
{
    private const L = GuaranteeAlerts::DEFAULT_LEVELS;

    public function test_paliers_selon_la_part_consommee(): void
    {
        // Période de 364 jours : 75 % le 1er octobre, 85 % le 7 novembre, 95 % le 13 décembre.
        $at = fn ($d) => GuaranteeAlerts::level('2026-01-01', '2026-12-31', $d, self::L);
        $this->assertNull($at('2026-09-30'));
        $this->assertSame(['VIGILANCE', 1], [$at('2026-10-01')['kind'], $at('2026-10-01')['perWeek']]);
        $this->assertSame(1, $at('2026-11-06')['perWeek']);
        $this->assertSame(2, $at('2026-11-07')['perWeek']);
        $this->assertSame(3, $at('2026-12-13')['perWeek']);
        $this->assertSame(['VIGILANCE', 3], [$at('2026-12-31')['kind'], $at('2026-12-31')['perWeek']]); // jour de l'échéance
        $this->assertSame(['RETARD', 4], [$at('2027-01-01')['kind'], $at('2027-01-01')['perWeek']]); // au-delà de 100 %
    }

    public function test_une_prolongation_ouvre_une_nouvelle_periode(): void
    {
        $g = ['start' => '2026-01-01', 'end' => '2027-03-31', 'extensions' => [
            ['start' => '2027-01-01', 'end' => '2027-03-31', 'type' => 'PROLONGATION'], ['start' => '2026-12-01', 'end' => '2027-02-01', 'type' => 'RENOUVELLEMENT'],
        ]];
        $this->assertSame(['start' => '2027-01-01', 'end' => '2027-03-31', 'type' => 'PROLONGATION'], GuaranteeAlerts::coverage($g));
        $this->assertNull(GuaranteeAlerts::level('2027-01-01', '2027-03-31', '2027-01-02', self::L));
        $this->assertSame(['start' => '2026-01-01', 'end' => '2026-12-31', 'type' => null], GuaranteeAlerts::coverage(['start' => '2026-01-01', 'end' => '2026-12-31', 'extensions' => []]));
    }

    public function test_jours_d_envoi_de_la_semaine(): void
    {
        // 30/10/2026 est un vendredi, 02/11/2026 un lundi.
        $this->assertTrue(GuaranteeAlerts::isDue(1, [], false, '2026-10-30')); // premier envoi du niveau : tout de suite
        $this->assertFalse(GuaranteeAlerts::isDue(1, ['2026-10-30'], true, '2026-10-30')); // jamais deux le même jour
        $this->assertFalse(GuaranteeAlerts::isDue(1, ['2026-10-30'], true, '2026-10-31')); // 1 par semaine déjà fait
        $this->assertTrue(GuaranteeAlerts::isDue(1, ['2026-10-30'], true, '2026-11-02')); // nouvelle semaine, lundi
        $this->assertSame('2026-11-02', GuaranteeAlerts::nextDate(1, ['2026-10-30'], true, '2026-10-31'));

        // 2 par semaine : lundi et jeudi, avec rattrapage le vendredi si le jeudi a été manqué.
        $this->assertFalse(GuaranteeAlerts::isDue(2, ['2026-11-02'], true, '2026-11-03'));
        $this->assertTrue(GuaranteeAlerts::isDue(2, ['2026-11-02'], true, '2026-11-05'));
        $this->assertTrue(GuaranteeAlerts::isDue(2, ['2026-11-02'], true, '2026-11-06'));
        $this->assertFalse(GuaranteeAlerts::isDue(2, ['2026-11-02', '2026-11-05'], true, '2026-11-06'));

        // 3 par semaine : lundi, mercredi, vendredi ; 4 par semaine : lundi, mardi, jeudi, vendredi.
        $this->assertTrue(GuaranteeAlerts::isDue(3, ['2026-11-02'], true, '2026-11-04'));
        $this->assertTrue(GuaranteeAlerts::isDue(4, ['2026-11-02'], true, '2026-11-03'));
        $this->assertFalse(GuaranteeAlerts::isDue(4, ['2026-11-02', '2026-11-03'], true, '2026-11-04'));

        // Passage en retard : le premier message de retard part aussitôt, même après une vigilance cette semaine.
        $this->assertTrue(GuaranteeAlerts::isDue(4, ['2026-11-02'], false, '2026-11-04'));
    }

    public function test_paliers_regles_par_l_administrateur(): void
    {
        $this->assertSame(GuaranteeAlerts::DEFAULT_LEVELS, GuaranteeAlerts::normalizeLevels(null));
        $this->assertSame(GuaranteeAlerts::DEFAULT_LEVELS, GuaranteeAlerts::normalizeLevels('{"vigilance":[]}'));
        $l = GuaranteeAlerts::normalizeLevels(['vigilance' => [['from' => 90, 'perWeek' => 9], ['from' => 70, 'perWeek' => 1]], 'retard' => 5]);
        $this->assertEquals(['vigilance' => [['from' => 70, 'perWeek' => 1], ['from' => 90, 'perWeek' => 7]], 'retard' => 5], $l);
    }

    public function test_messages_adaptes_au_contrat_avec_le_numero_de_garantie(): void
    {
        $vars = ['contrat' => '144/PRISE/2026', 'numero_garantie' => 'GBE-0144', 'echeance' => '31/12/2026', 'nature' => 'la prolongation', 'projet' => 'PRISE II'];
        $v = GuaranteeAlerts::message('VIGILANCE', $vars);
        $this->assertStringContainsString('garantie de bonne exécution n° GBE-0144 relative au contrat n° 144/PRISE/2026 arrive à échéance le 31/12/2026.', $v['text']);
        $this->assertSame('[PRISE II] Garantie de bonne exécution n° GBE-0144 — contrat n° 144/PRISE/2026 — alerte de vigilance', $v['subject']);
        $this->assertStringEndsWith("Cordialement.\n\nLa Coordination nationale du projet PRISE II", $v['text']);
        $r = GuaranteeAlerts::message('RETARD', $vars);
        $this->assertStringContainsString('n° GBE-0144 relative au contrat n° 144/PRISE/2026 est arrivée à échéance depuis le 31/12/2026.', $r['text']);
        $this->assertStringContainsString('Nous comptons sur votre diligence.', $r['text']);
        $s = GuaranteeAlerts::message('SATISFACTION', $vars);
        $this->assertStringContainsString('Nous avons bien enregistré la prolongation de votre garantie de bonne exécution n° GBE-0144', $s['text']);

        // Modèle modifié par l'administrateur ; un n° de garantie absent est signalé plutôt que laissé vide.
        $custom = GuaranteeAlerts::message('VIGILANCE', ['numero_garantie' => '', 'contrat' => 'C-1'], ['subject' => 'Rappel {contrat}', 'body' => 'Garantie n° {numero_garantie}.']);
        $this->assertSame(['subject' => 'Rappel C-1', 'text' => 'Garantie n° non renseigné.'], $custom);
    }

    public function test_champs_des_modeles(): void
    {
        $this->assertSame([], AlertTemplates::unknownFields('GARANTIE_RETARD', 'n° {numero_garantie} du {contrat}, échue le {echeance}'));
        $this->assertSame(['numero_garantie'], AlertTemplates::unknownFields('CONTRAT_RETARD', '{contrat} {numero_garantie}'));
        foreach (AlertTemplates::DEFAULTS as $code => $tpl) {
            $this->assertSame([], AlertTemplates::unknownFields($code, $tpl['subject'] . $tpl['body']), $code);
            if (str_starts_with($code, 'GARANTIE')) $this->assertStringContainsString('{numero_garantie}', $tpl['body'] . $tpl['subject'], $code);
        }
    }
}
