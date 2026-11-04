<?php

namespace App\Support;

/**
 * « Situation du contrat » : calcul de chaque ligne de décompte, sur le modèle du document du projet et les règles
 * données le 28/10/2026 (vérifiées sur la ligne du décompte N° 1 du modèle) :
 *  - avance perçue = montant du contrat × taux d'avance de démarrage (aucune avance pour un contrat de service) ;
 *  - avance de démarrage retenue = montant du décompte × taux d'avance (0,2), plafonnée au solde de l'avance ;
 *  - retenue de garantie B.E. = montant du décompte × taux de la garantie de bonne exécution (0,05) ;
 *  - montant payé = montant du décompte − avance retenue − retenue de garantie − pénalité de retard (dernier décompte d'un marché en procédure nationale) ;
 *  - solde contrat = montant du contrat − avance perçue − montants payés ; solde avance = avance perçue − avances retenues.
 * Les lignes rejetées par le comptable ne comptent pas ; celles en attente comptent à titre provisoire.
 * Fonctions pures, testées sans démarrer Laravel.
 */
class DecompteLedger
{
    public const PENDING = 'EN_ATTENTE';
    public const VALID = 'VALIDE';
    public const REJECTED = 'REJETE';
    public const DECOMPTE = 'DECOMPTE';
    public const RELEASE = 'LIBERATION';

    /** Avance perçue au démarrage. */
    public static function advanceReceived(float $amount, float $advanceRate): float
    {
        return round($amount * $advanceRate, 2);
    }

    /**
     * Lignes calculées, dans l'ordre des N° de ligne. Ligne : ['id', 'line_no', 'gross_amount', 'advance_rate', 'guarantee_rate', 'status'].
     * Ajoute advance, guarantee, net, contractBalance, advanceBalance (null pour une ligne rejetée).
     */
    public static function rows(float $amount, float $advanceReceived, array $lines): array
    {
        usort($lines, fn ($a, $b) => $a['line_no'] <=> $b['line_no']);
        $contractBalance = round($amount - $advanceReceived, 2);
        $advanceBalance = $advanceReceived;
        $out = [];
        foreach ($lines as $l) {
            if ($l['status'] === self::REJECTED) {
                $out[] = $l + ['penalty' => null, 'advance' => null, 'guarantee' => null, 'net' => null, 'contractBalance' => null, 'advanceBalance' => null];
                continue;
            }
            $gross = (float) $l['gross_amount'];
            if (($l['kind'] ?? self::DECOMPTE) === self::RELEASE) {
                // Ligne « RETENUE » : la garantie retenue est reversée à l'entreprise ; aucune nouvelle retenue.
                $contractBalance = round($contractBalance - $gross, 2);
                $out[] = $l + ['penalty' => 0.0, 'advance' => 0.0, 'guarantee' => round(-$gross, 2), 'net' => round($gross, 2), 'contractBalance' => $contractBalance, 'advanceBalance' => $advanceBalance];
                continue;
            }
            $advance = round(min($gross * (float) $l['advance_rate'], max(0.0, $advanceBalance)), 2);
            $guarantee = round($gross * (float) $l['guarantee_rate'], 2);
            // La pénalité est figée à la saisie ; elle ne dépasse jamais ce que la ligne peut supporter.
            $penalty = round(min((float) ($l['penalty_amount'] ?? 0), max(0.0, $gross - $advance - $guarantee)), 2);
            $net = round($gross - $advance - $guarantee - $penalty, 2);
            $contractBalance = round($contractBalance - $net - $penalty, 2);
            $advanceBalance = round($advanceBalance - $advance, 2);
            $out[] = $l + compact('penalty', 'advance', 'guarantee', 'net', 'contractBalance', 'advanceBalance');
        }
        return $out;
    }

    /** Totaux des lignes validées, et des lignes en attente de décision. */
    public static function totals(array $rows): array
    {
        $t = ['penalty' => 0.0, 'gross' => 0.0, 'advance' => 0.0, 'guarantee' => 0.0, 'net' => 0.0, 'released' => 0.0, 'validated' => 0, 'pending' => 0, 'pendingGross' => 0.0, 'rejected' => 0];
        foreach ($rows as $r) {
            if ($r['status'] === self::REJECTED) { $t['rejected']++; continue; }
            if ($r['status'] === self::PENDING) { $t['pending']++; $t['pendingGross'] += (float) $r['gross_amount']; continue; }
            $t['validated']++;
            if (($r['kind'] ?? self::DECOMPTE) === self::RELEASE) { $t['released'] += $r['net']; $t['net'] += $r['net']; continue; }
            $t['gross'] += (float) $r['gross_amount'];
            $t['advance'] += $r['advance'];
            $t['guarantee'] += $r['guarantee'];
            $t['penalty'] += $r['penalty'];
            $t['net'] += $r['net'];
        }
        foreach (['penalty', 'gross', 'advance', 'guarantee', 'net', 'released', 'pendingGross'] as $k) $t[$k] = round($t[$k], 2);
        $t['held'] = round($t['guarantee'] - $t['released'], 2); // garantie encore retenue
        return $t;
    }
}
