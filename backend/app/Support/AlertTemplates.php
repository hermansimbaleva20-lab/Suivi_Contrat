<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Modèles des messages : aux entreprises (alerte de vigilance, alerte de retard, message de satisfaction) et au personnel
 * du projet (rappel hebdomadaire d'attention), pour la garantie de bonne exécution et pour le contrat ; au chef de cellule
 * infrastructures (rappel d'un décompte à valider). Un administrateur de la coordination nationale (ou le super-admin)
 * modifie l'objet et le texte (table alert_templates) ; sans modification, le modèle par défaut s'applique.
 * Les champs entre accolades ({contrat}, {numero_garantie}…) sont remplacés à chaque envoi par les valeurs du contrat.
 */
class AlertTemplates
{
    public const SIGNATURE = "Cordialement.\n\nLa Coordination nationale du projet {projet}";

    public const LABELS = [
        'GARANTIE_VIGILANCE' => 'Garantie — alerte de vigilance', 'GARANTIE_RETARD' => 'Garantie — alerte de retard', 'GARANTIE_SATISFACTION' => 'Garantie — message de satisfaction',
        'CONTRAT_VIGILANCE' => 'Contrat — alerte de vigilance', 'CONTRAT_RETARD' => 'Contrat — alerte de retard', 'CONTRAT_SATISFACTION' => 'Contrat — message de satisfaction',
        'GARANTIE_PERSONNEL' => 'Personnel du projet — garantie à suivre', 'CONTRAT_PERSONNEL' => 'Personnel du projet — contrat à suivre',
        'DECOMPTE_RAPPEL' => 'Chef de cellule infrastructures — décompte à valider',
    ];

    /** Quand chaque message part. */
    public const WHEN = [
        'GARANTIE_VIGILANCE' => 'Automatique : à partir de 75 % de la période de garantie consommée, selon la séquence des relances.',
        'GARANTIE_RETARD' => 'Automatique : dès le lendemain de l’échéance de la garantie, selon la séquence des relances.',
        'GARANTIE_SATISFACTION' => 'Automatique, une fois : après la prolongation ou le renouvellement d’une garantie qui avait fait l’objet de relances.',
        'CONTRAT_VIGILANCE' => 'Automatique, une fois par semaine : tant que le contrat est en vigilance. Le bouton de la page Alertes l’envoie aussi à la demande.',
        'CONTRAT_RETARD' => 'Automatique, une fois par semaine : tant que le contrat est en retard. Le bouton de la page Alertes l’envoie aussi à la demande.',
        'CONTRAT_SATISFACTION' => 'Automatique, une fois : quand le contrat est terminé. Le bouton de la page Alertes l’envoie aussi à la demande.',
        'GARANTIE_PERSONNEL' => 'Automatique, une fois par semaine : dès le premier palier de la garantie, jusqu’à sa prolongation ou son renouvellement.',
        'CONTRAT_PERSONNEL' => 'Automatique, une fois par semaine : tant que le contrat est en vigilance ou en retard, jusqu’à l’amélioration de l’avancement physique.',
        'DECOMPTE_RAPPEL' => 'Automatique, chaque jour : un jour après la transmission d’un décompte encore en attente, à l’adresse du compte habilité à le valider.',
    ];

    /** Champ propre aux messages du personnel du projet. */
    public const STAFF_FIELD = ['niveau' => ['Niveau de l’alerte : « vigilance » ou « retard »', 'vigilance']];

    /** Champs disponibles : [libellé, exemple]. */
    public const FIELDS = [
        'GARANTIE' => [
            'contrat' => ['N° du contrat', '144/PRISE/2026'], 'numero_garantie' => ['N° de la garantie', 'GBE-2026-0144'], 'entreprise' => ['Entreprise', 'GENERTEL GROUPE'],
            'banque' => ['Banque émettrice', 'Rawbank'], 'montant_garanti' => ['Montant garanti (USD)', '12 500,00'], 'debut' => ['Début de la période en cours', '15/01/2026'],
            'echeance' => ['Échéance de la garantie', '15/01/2027'], 'jours_consommes' => ['Part consommée de la période', '82,5 %'],
            'nature' => ['Régularisation : « la prolongation » ou « le renouvellement »', 'la prolongation'], 'projet' => ['Projet', 'PRISE II'],
        ],
        'CONTRAT' => [
            'contrat' => ['N° du contrat', '144/PRISE/2026'], 'entreprise' => ['Entreprise', 'GENERTEL GROUPE'], 'intitule' => ['Intitulé du contrat', 'Construction de forages'],
            'avancement' => ['Avancement physique', '45,0 %'], 'jours_consommes' => ['Délai consommé', '78,0 %'], 'date_debut' => ['Date de démarrage', '15/01/2026'],
            'date_fin' => ['Date de fin prévue', '15/01/2027'], 'projet' => ['Projet', 'PRISE II'],
        ],
        'DECOMPTE' => [
            'decompte' => ['N° du décompte', 'DECOMPTE N°1'], 'contrat' => ['N° du contrat', '144/PRISE/2026'], 'entreprise' => ['Entreprise', 'GENERTEL GROUPE'],
            'montant' => ['Montant du décompte (USD)', '45 000,00'], 'date_decompte' => ['Date du décompte', '02/11/2026'], 'transmis_le' => ['Date de transmission', '02/11/2026'],
            'saisi_par' => ['Personne qui a établi le décompte', 'Jorcelin MIKADO'], 'attente' => ['Attente depuis la transmission', '1 jour'],
            'lien' => ['Lien vers le décompte dans l’application', 'https://suivi.exemple/decomptes?contrat=144'], 'projet' => ['Projet', 'PRISE II'],
        ],
    ];

    public const DEFAULTS = [
        'GARANTIE_VIGILANCE' => [
            'subject' => '[{projet}] Garantie de bonne exécution n° {numero_garantie} — contrat n° {contrat} — alerte de vigilance',
            'body' => "Cher partenaire,\n\n"
                . "Nous vous rappelons que votre garantie de bonne exécution n° {numero_garantie} relative au contrat n° {contrat} arrive à échéance le {echeance}.\n\n"
                . "Au regard de l'état d'avancement de la période contractuelle, nous vous invitons à prendre, dans les meilleurs délais, les dispositions nécessaires en vue de la prolongation ou du renouvellement de ladite garantie, afin d'assurer la continuité de la couverture financière et la poursuite normale des activités du projet.\n\n"
                . "Nous vous remercions de bien vouloir prendre les mesures appropriées et de nous transmettre les justificatifs correspondants.\n\n" . self::SIGNATURE,
        ],
        'GARANTIE_RETARD' => [
            'subject' => '[{projet}] Garantie de bonne exécution n° {numero_garantie} — contrat n° {contrat} — alerte de retard',
            'body' => "Cher partenaire,\n\n"
                . "Nous constatons que votre garantie de bonne exécution n° {numero_garantie} relative au contrat n° {contrat} est arrivée à échéance depuis le {echeance}.\n\n"
                . "À ce jour, aucune prolongation ou preuve de renouvellement de cette garantie n'a été enregistrée dans notre système.\n\n"
                . "Nous vous demandons de prendre, sans délai, toutes les dispositions nécessaires pour procéder à sa régularisation et nous transmettre le justificatif de prolongation ou de renouvellement dans les meilleurs délais.\n\n"
                . "Nous attirons votre attention sur l'importance du maintien de la garantie de bonne exécution pendant toute la période requise du contrat.\n\n"
                . "Nous comptons sur votre diligence.\n\n" . self::SIGNATURE,
        ],
        'GARANTIE_SATISFACTION' => [
            'subject' => '[{projet}] Garantie de bonne exécution n° {numero_garantie} — contrat n° {contrat} — régularisation enregistrée',
            'body' => "Cher partenaire,\n\n"
                . "Nous avons bien enregistré {nature} de votre garantie de bonne exécution n° {numero_garantie} relative au contrat n° {contrat}, désormais valable jusqu'au {echeance}.\n\n"
                . "Nous vous remercions pour votre diligence et pour le maintien de la couverture financière du contrat.\n\n" . self::SIGNATURE,
        ],
        'CONTRAT_VIGILANCE' => [
            'subject' => '[{projet}] Contrat n° {contrat} — alerte de vigilance',
            'body' => "Cher partenaire,\n\n"
                . "Nous attirons votre attention sur l'exécution du contrat n° {contrat} : à ce jour, l'avancement physique est de {avancement} pour {jours_consommes} du délai contractuel consommé, la fin étant prévue le {date_fin}.\n\n"
                . "Nous vous invitons à prendre, dans les meilleurs délais, les dispositions nécessaires pour accélérer le rythme d'exécution afin de respecter le délai contractuel.\n\n" . self::SIGNATURE,
        ],
        'CONTRAT_RETARD' => [
            'subject' => '[{projet}] Contrat n° {contrat} — alerte de retard',
            'body' => "Cher partenaire,\n\n"
                . "Nous constatons un retard dans l'exécution du contrat n° {contrat} : l'avancement physique est de {avancement} alors que {jours_consommes} du délai contractuel est consommé (fin prévue le {date_fin}).\n\n"
                . "Nous vous demandons de prendre, sans délai, les mesures correctives nécessaires et de nous transmettre un planning de rattrapage.\n\n"
                . "Nous comptons sur votre diligence.\n\n" . self::SIGNATURE,
        ],
        'CONTRAT_SATISFACTION' => [
            'subject' => '[{projet}] Contrat n° {contrat} — exécution achevée',
            'body' => "Cher partenaire,\n\n"
                . "Nous avons le plaisir de constater que le contrat n° {contrat} a atteint {avancement} d'avancement physique.\n\n"
                . "Nous vous remercions pour la qualité de votre collaboration et vous invitons à préparer les formalités de réception.\n\n" . self::SIGNATURE,
        ],
        'GARANTIE_PERSONNEL' => [
            'subject' => '[{projet}] Pour attention — garantie n° {numero_garantie} du contrat n° {contrat} ({niveau})',
            'body' => "Chers collègues,\n\n"
                . "Nous attirons votre attention sur la garantie de bonne exécution n° {numero_garantie} du contrat n° {contrat} ({entreprise}, banque {banque}), en situation de {niveau} : {jours_consommes} de la période consommés, échéance le {echeance}.\n\n"
                . "Merci d'accorder une attention particulière à ce dossier jusqu'à la prolongation ou au renouvellement de la garantie.\n\n"
                . "Ce rappel vous est envoyé une fois par semaine par l'application de suivi {projet}.",
        ],
        'CONTRAT_PERSONNEL' => [
            'subject' => '[{projet}] Pour attention — contrat n° {contrat} ({niveau})',
            'body' => "Chers collègues,\n\n"
                . "Nous attirons votre attention sur le contrat n° {contrat} ({entreprise}), en situation de {niveau} : avancement physique de {avancement} pour {jours_consommes} du délai consommé, fin prévue le {date_fin}.\n\n"
                . "Merci d'accorder une attention particulière à ce dossier jusqu'à l'amélioration de l'avancement physique.\n\n"
                . "Ce rappel vous est envoyé une fois par semaine par l'application de suivi {projet}.",
        ],
        'DECOMPTE_RAPPEL' => [
            'subject' => '[{projet}] Rappel — {decompte} du contrat n° {contrat} à valider',
            'body' => "Bonjour,\n\n"
                . "Le {decompte} du contrat n° {contrat} ({entreprise}), d'un montant de {montant} USD, vous a été transmis le {transmis_le} par {saisi_par} et attend toujours votre décision.\n\n"
                . "Merci de le valider ou de le rejeter dans l'application de suivi : {lien}\n\n"
                . "Ce rappel vous est envoyé chaque jour tant que le décompte n'a pas été traité.\n\n"
                . "Application de suivi {projet}",
        ],
    ];

    public static function group(string $code): string
    {
        foreach (['GARANTIE', 'DECOMPTE'] as $g) if (str_starts_with($code, $g)) return $g;
        return 'CONTRAT';
    }

    /** Message destiné au personnel du projet, et non à l'entreprise. */
    public static function isStaff(string $code): bool
    {
        return str_ends_with($code, '_PERSONNEL');
    }

    /** Champs disponibles dans un modèle : [clé => [libellé, exemple]]. */
    public static function fields(string $code): array
    {
        return self::FIELDS[self::group($code)] + (self::isStaff($code) ? self::STAFF_FIELD : []);
    }

    /** Champs entre accolades du texte qui ne figurent pas dans la liste du modèle. */
    public static function unknownFields(string $code, string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);
        return array_values(array_unique(array_diff($m[1], array_keys(self::fields($code)))));
    }

    /** Remplace les champs connus ; un champ vide devient « non renseigné ». */
    public static function render(array $tpl, array $vars): array
    {
        $map = [];
        foreach ($vars as $k => $v) $map['{' . $k . '}'] = trim((string) $v) === '' ? 'non renseigné' : (string) $v;
        return ['subject' => strtr($tpl['subject'], $map), 'text' => strtr($tpl['body'], $map)];
    }

    /** Les modèles du projet : modifiés s'ils l'ont été, sinon par défaut. */
    public static function all(int $projectId): array
    {
        $saved = DB::table('alert_templates')->where('project_id', $projectId)->get()->keyBy('code');
        $out = [];
        foreach (self::DEFAULTS as $code => $tpl) {
            $s = $saved[$code] ?? null;
            $out[$code] = $s ? ['subject' => (string) $s->subject, 'body' => (string) $s->body] : $tpl;
        }
        return $out;
    }
}
