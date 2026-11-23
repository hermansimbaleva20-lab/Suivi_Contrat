<?php

namespace App\Support;

/**
 * Mode d'acquisition, caution d'avance de démarrage et garantie de bonne exécution (décision du 22/11/2026), sur le modèle
 * des tableaux de suivi du projet : TITULAIRE, MONTANT($), MODE D'ACQUISITION, MARCHÉ (date de début d'exécution, date de
 * fin contractuelle), puis la caution ou la garantie (référence et date, date d'expiration, validité, observations).
 * Les colonnes du marché viennent du contrat, saisies une seule fois ; les dates de la caution et de la garantie sont les
 * leurs, distinctes de celles du marché. Fonctions pures, sur les contrats de Loader.
 */
final class Cautions
{
    /** Modes d'acquisition proposés ; un autre mode s'enregistre en texte libre. */
    public const MODES = [
        'AON' => 'Appel d’offres national',
        'AOI' => 'Appel d’offres international',
        'CF' => 'Consultation de fournisseurs',
        'ED' => 'Entente directe',
    ];

    public const OTHER = 'AUTRE';

    /** Valeur enregistrée → [code du formulaire, texte d'un autre mode]. */
    public static function modeInput(?string $stored): array
    {
        $stored = trim((string) $stored);
        if ($stored === '') return ['', ''];
        return isset(self::MODES[$stored]) ? [$stored, ''] : [self::OTHER, $stored];
    }

    /** Mode d'acquisition lu du formulaire : un code de la liste, ou le texte d'un autre mode (80 caractères au plus). */
    public static function modeFromInput(string $code, string $other, ?string &$error): ?string
    {
        $error = null;
        $code = trim($code);
        $other = trim($other);
        if ($code === '') {
            $error = 'Choisissez le mode d’acquisition du marché.';
            return null;
        }
        if (isset(self::MODES[$code])) return $code;
        if ($code !== self::OTHER) {
            $error = 'Mode d’acquisition inconnu : choisissez-le dans la liste.';
            return null;
        }
        if ($other === '') {
            $error = 'Précisez le mode d’acquisition.';
            return null;
        }
        if (mb_strlen($other) > 80) {
            $error = 'Le mode d’acquisition ne doit pas dépasser 80 caractères.';
            return null;
        }
        return $other;
    }

    /** Libellé complet (« Appel d'offres national ») ; un autre mode est affiché tel qu'il a été saisi. */
    public static function modeLabel(?string $stored): string
    {
        $stored = trim((string) $stored);
        return self::MODES[$stored] ?? $stored;
    }

    /** Validité, en jours, de la date de la caution (ou d'émission) à sa date d'expiration : 20/03/2024 → 19/07/2024 = 121 jours. */
    public static function validity(?string $from, ?string $to): ?int
    {
        return $from && $to ? Dates::daysBetween($from, $to) : null;
    }

    /** Ligne commune des deux tableaux : colonnes du titulaire et du marché, reprises du contrat. */
    private static function base(array $c): array
    {
        return [
            'contractId' => $c['id'], 'contractNumber' => $c['number'], 'holder' => $c['company'],
            'amount' => (float) $c['amount'], 'mode' => trim((string) ($c['acquisition_mode'] ?? '')),
            'start' => $c['start_date'], 'end' => $c['end_date'],
        ];
    }

    private static function sort(array $rows): array
    {
        usort($rows, fn ($a, $b) => strcmp((string) $a['start'], (string) $b['start']) ?: strcmp((string) $a['contractNumber'], (string) $b['contractNumber']));
        return $rows;
    }

    /**
     * Tableau « Caution d'avance de démarrage » : les contrats avec une avance (taux du contrat, ou avance demandée d'un marché
     * de biens BAD ou national). La caution est celle saisie avec le contrat ; à défaut, la garantie de restitution de la
     * demande d'avance. Une caution expirée à la date d'arrêt est signalée ; sans observation saisie, la colonne le dit.
     */
    public static function advanceRows(array $contracts, string $asOf): array
    {
        $rows = [];
        foreach ($contracts as $c) {
            $request = $c['advance'] ?? null;
            if ((float) ($c['initial_rate'] ?? 0) <= 0 && !$request) continue;
            $g = $c['advance_guarantee'] ?? null;
            if ($g) {
                [$ref, $bank, $from, $to, $obs] = [$g['reference'], $g['bank'], $g['issue_date'], $g['end_date'], $g['observation']];
            } elseif ($request && trim((string) $request['guarantee_number']) !== '') {
                // Date de la caution (23/11/2026) ; une demande plus ancienne n'a que la date de la demande.
                $from = $request['guarantee_issue_date'] ?? null ?: $request['request_date'];
                [$ref, $bank, $to, $obs] = [(string) $request['guarantee_number'], (string) $request['guarantee_bank'], $request['guarantee_end_date'], ''];
            } else {
                [$ref, $bank, $from, $to, $obs] = ['', '', null, null, ''];
            }
            $missing = trim((string) $ref) === '';
            $expired = !$missing && $to !== null && $asOf > $to;
            $auto = $missing ? 'Caution non renseignée' : ($expired ? 'Caution expirée' : '');
            $rows[] = self::base($c) + [
                'reference' => (string) $ref, 'bank' => (string) $bank, 'date' => $from, 'expiry' => $to, 'validity' => self::validity($from, $to),
                'observation' => trim((string) $obs) !== '' ? trim((string) $obs) : $auto, 'expired' => $expired, 'missing' => $missing,
            ];
        }
        return self::sort($rows);
    }

    /**
     * Tableau « Garantie de bonne exécution » : un contrat par garantie, la date d'émission et la date d'expiration de la
     * période en cours à la date d'arrêt (dernière prolongation ou dernier renouvellement). $sent : identifiants des garanties
     * dont une relance est partie, pour écrire « correspondance adressée » ; une observation saisie reste prioritaire.
     */
    public static function guaranteeRows(array $contracts, string $asOf, array $sent = []): array
    {
        $rows = [];
        foreach ($contracts as $c) {
            $g = $c['guarantee'] ?? null;
            if (!$g) continue;
            $at = Metrics::guaranteeAt($g, $asOf);
            $from = $g['start'];
            $to = $at['end'];
            $renewed = !empty($g['contract_renewed']);
            $expired = !$renewed && $to !== null && $asOf > $to;
            $auto = $renewed ? 'Non exigée (contrat renouvelé)' : ($expired ? 'Garantie expirée' . (isset($sent[$g['id']]) ? ', correspondance adressée' : '') : '');
            $rows[] = self::base($c) + [
                'reference' => (string) $g['account'], 'bank' => (string) $g['bank'], 'date' => $from, 'expiry' => $to, 'validity' => self::validity($from, $to),
                'observation' => trim((string) $g['observation']) !== '' ? trim((string) $g['observation']) : $auto, 'expired' => $expired, 'missing' => false,
                'guaranteeId' => $g['id'],
            ];
        }
        return self::sort($rows);
    }
}
