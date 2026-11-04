<?php

namespace App\Support;

/**
 * Marchés de biens : règles du document « Procédure pour marché de biens » (une seule procédure par marché, BAD ou nationale).
 * Les taux sont repris sur le contrat à sa création et restent modifiables. L'échéancier est indicatif : les paiements suivent
 * les décomptes et leurs retenues. Les fournitures de bureau échappent à ces procédures : elles ne se suivent que par le stock
 * de leurs articles (entrées et sorties), sans garantie, avance, décompte, paiement ni avancement ; elles peuvent être renouvelées.
 * Fonctions pures, testées sans démarrer Laravel.
 */
class GoodsProcedure
{
    public const BAD = 'BAD';
    public const NATIONAL = 'NATIONALE';
    public const OFFICE = 'FOURNITURES_BUREAU';
    public const FOREIGN = 'ETRANGER';
    public const LOCAL = 'LOCAL';
    public const OFFICE_MIN_AMOUNT = 500.0;
    public const OFFICE_MAX_AMOUNT = 5500.0;
    public const STOCK_ONLY_MESSAGE = 'Ce contrat de fournitures de bureau se suit uniquement par le stock de ses articles : ni avance, ni décompte, ni paiement, ni avancement.';

    private const LABELS = [
        self::BAD => 'Procédure BAD',
        self::NATIONAL => 'Procédure nationale',
        self::OFFICE => 'Fournitures de bureau',
    ];
    private const ORIGINS = [
        self::FOREIGN => 'Provenance de l’étranger',
        self::LOCAL => 'Provenance locale (RDC)',
    ];

    private const RULES = [
        self::BAD => [
            'guaranteeRate' => 10.0, 'penaltyRate' => null, 'penaltyCap' => null,
            self::FOREIGN => ['advanceRate' => 10.0, 'tranches' => [
                ['key' => 'EMBARQUEMENT', 'label' => 'À l’embarquement', 'rate' => 80.0, 'condition' => 'Par lettre de crédit confirmée et irrévocable, contre les documents de la clause 13 du CCAG.'],
                ['key' => 'ACCEPTATION', 'label' => 'À l’acceptation', 'rate' => 10.0, 'condition' => 'Dans les 30 jours suivant la réception, contre une demande de règlement et le certificat d’acceptation.'],
            ]],
            self::LOCAL => ['advanceRate' => 10.0, 'tranches' => [
                ['key' => 'LIVRAISON', 'label' => 'À la livraison', 'rate' => 80.0, 'condition' => 'À la réception des biens, contre les documents de la clause 13 du CCAG.'],
                ['key' => 'ACCEPTATION', 'label' => 'À l’acceptation', 'rate' => 10.0, 'condition' => 'Dans les 30 jours suivant la date du certificat d’acceptation.'],
            ]],
        ],
        self::NATIONAL => [
            'guaranteeRate' => 5.0, 'penaltyRate' => 0.05, 'penaltyCap' => 10.0,
            self::FOREIGN => ['advanceRate' => 15.0, 'tranches' => [
                ['key' => 'EMBARQUEMENT', 'label' => 'À l’embarquement', 'rate' => 65.0, 'condition' => 'Par lettre de crédit confirmée et irrévocable, contre les documents de l’article 12 du CCAG.'],
                ['key' => 'RECEPTION', 'label' => 'À la réception', 'rate' => 20.0, 'condition' => 'Dans les 30 jours suivant la réception, contre une demande de règlement et le procès-verbal de réception.'],
            ]],
            self::LOCAL => ['advanceRate' => 20.0, 'tranches' => [
                ['key' => 'RECEPTION', 'label' => 'À la réception', 'rate' => 80.0, 'condition' => 'Dans les 30 jours suivant la réception sans réserve, contre une demande de règlement et le procès-verbal de réception.'],
            ]],
        ],
    ];

    private const CLAUSES = [
        self::BAD => [
            'Prix non révisables (CCAG 15.1).',
            'Règlement en dollars américains (CCAG 16.1).',
            'Garantie de bonne exécution : garantie bancaire, en monnaie librement convertible acceptable par l’Acheteur (CCAG 18.1, 18.3).',
            'Garantie de bonne exécution libérée au plus tard 28 jours après l’achèvement des obligations du Fournisseur, y compris les obligations de garantie (CCAG 18.4).',
        ],
        self::NATIONAL => [
            'Garantie de bonne exécution : garantie bancaire (CCAG 16.1, 16.3).',
            'Intérêts moratoires au-delà de 60 jours, au taux d’escompte de l’Institut d’émission majoré de 2 % (CCAG 14.4).',
            'Valeur assurée : 110 % de la valeur DDP rendue à destination des fournitures (CCAG 22.1).',
            'Inspections et essais à la réception, au lieu de destination finale ; réception sanctionnée par un procès-verbal (CCAG 24.1, 24.2).',
        ],
    ];

    private const DOCUMENTS = [
        self::BAD => [
            self::FOREIGN => [
                'note' => 'À recevoir au moins une semaine avant l’arrivée au port ou la date de livraison à destination finale.',
                'items' => [
                    '3 copies des factures du Fournisseur (fournitures, quantités, prix unitaire et montant total)',
                    '1 original et 3 copies du connaissement négociable, net à bord, marqué « frais payé », et 3 copies du connaissement non négociable',
                    '3 copies des listes de colisage',
                    '1 certificat d’assurance',
                    '1 certificat de garantie du Fabriquant ou du Fournisseur',
                    '1 certificat d’inspection émis par le service d’inspection désigné',
                    '1 certificat d’origine',
                ],
            ],
            self::LOCAL => [
                'note' => 'À transmettre dès la réception des fournitures par le transporteur (clause type EXW).',
                'items' => [
                    '3 copies de la facture du Fournisseur (fournitures, quantité, prix unitaire et montant total)',
                    '1 notification de la livraison ou reçu du transporteur ferroviaire ou routier',
                    '1 certificat de garantie du Fabriquant ou du Fournisseur',
                    '1 certificat d’inspection émis par le service d’inspection désigné',
                    '1 certificat d’origine',
                ],
            ],
        ],
    ];

    /** BAD ou nationale : conditions du document, avance contre une garantie de restitution. */
    public static function governs(?string $procedure): bool
    {
        return $procedure === self::BAD || $procedure === self::NATIONAL;
    }

    /** Fournitures de bureau : suivi par le stock uniquement. */
    public static function stockOnly(?string $procedure): bool
    {
        return $procedure === self::OFFICE;
    }

    /** Seuls les contrats de biens hors procédure (fournitures de bureau, anciens contrats) se renouvellent. */
    public static function renewable(string $category, ?string $procedure): bool
    {
        return in_array(strtoupper($category), ['BIENS', 'BIEN'], true) && !self::governs($procedure);
    }

    public static function label(?string $procedure): ?string
    {
        return self::LABELS[$procedure] ?? null;
    }

    public static function originLabel(?string $origin): ?string
    {
        return self::ORIGINS[$origin] ?? null;
    }

    /** Valeurs du document, pour préremplir le formulaire. */
    public static function catalog(): array
    {
        $rules = [];
        foreach ([self::BAD, self::NATIONAL] as $p) {
            $rules[$p] = ['guaranteeRate' => self::RULES[$p]['guaranteeRate'], 'penaltyRate' => self::RULES[$p]['penaltyRate'], 'penaltyCap' => self::RULES[$p]['penaltyCap']];
            foreach ([self::FOREIGN, self::LOCAL] as $o) $rules[$p][$o] = self::RULES[$p][$o];
        }
        return [
            'procedures' => array_map(fn ($code) => ['code' => $code, 'label' => self::LABELS[$code]], array_keys(self::LABELS)),
            'origins' => array_map(fn ($code) => ['code' => $code, 'label' => self::ORIGINS[$code]], array_keys(self::ORIGINS)),
            'rules' => $rules,
            'officeAmounts' => ['min' => self::OFFICE_MIN_AMOUNT, 'max' => self::OFFICE_MAX_AMOUNT],
        ];
    }

    public static function defaults(string $procedure, string $origin): array
    {
        return self::RULES[$procedure][$origin];
    }

    public static function defaultPenalty(string $procedure): array
    {
        return [self::RULES[$procedure]['penaltyRate'], self::RULES[$procedure]['penaltyCap']];
    }

    /**
     * Échéancier du contrat : les tranches du document, avec les taux saisis (Dates::decimal accepte la virgule).
     * Renvoie [tranches, erreur] ; l'avance et les tranches doivent totaliser 100 %.
     */
    public static function schedule(string $procedure, string $origin, array $input, float $advanceRate): array
    {
        $given = [];
        foreach ($input as $row) if (is_array($row) && isset($row['key'])) $given[$row['key']] = $row['rate'] ?? null;
        $total = $advanceRate;
        $out = [];
        foreach (self::RULES[$procedure][$origin]['tranches'] as $t) {
            $rate = array_key_exists($t['key'], $given) ? Dates::decimal($given[$t['key']]) : $t['rate'];
            if ($rate === null || $rate < 0 || $rate > 100) return [[], "Le taux de la tranche « {$t['label']} » doit être compris entre 0 et 100 %."];
            $total += $rate;
            $out[] = ['key' => $t['key'], 'label' => $t['label'], 'rate' => $rate, 'condition' => $t['condition']];
        }
        if (abs($total - 100) > 0.001) return [[], 'L’avance et les tranches doivent totaliser 100 % (total actuel : ' . round($total, 2) . ' %).'];
        return [$out, null];
    }

    /** Fiche du contrat : procédure, conditions et documents à fournir ; null pour un contrat hors procédure. */
    public static function describe(?string $procedure, ?string $origin): ?array
    {
        if (!self::governs($procedure)) return null;
        $documents = self::DOCUMENTS[$procedure][$origin] ?? null;
        return [
            'advanceCondition' => $procedure === self::BAD
                ? 'Dans les 30 jours suivant la signature, sur demande de paiement, contre une garantie bancaire de restitution d’un montant équivalent, valable jusqu’à la livraison des biens.'
                : 'Dans les 30 jours suivant la signature, sur demande de paiement, contre une garantie bancaire de restitution d’un montant équivalent, valable jusqu’à la livraison des fournitures.',
            'clauses' => self::CLAUSES[$procedure],
            'documents' => $documents['items'] ?? [],
            'documentsNote' => $documents['note'] ?? 'Documents de l’article 12 du CCAG.',
        ];
    }

    /**
     * Pénalité de retard de la procédure nationale : taux hebdomadaire × montant du marché × semaines complètes de retard
     * entre la fin du contrat et la réception, plafonnée à un pourcentage du montant du marché.
     */
    public static function penalty(float $amount, ?float $weeklyRate, ?float $cap, ?string $endDate, string $receptionDate): array
    {
        if (!$weeklyRate || !$endDate || $receptionDate <= $endDate) return ['weeks' => 0, 'amount' => 0.0];
        $weeks = (int) floor(Dates::daysBetween($endDate, $receptionDate) / 7);
        $raw = $amount * $weeklyRate / 100 * $weeks;
        return ['weeks' => $weeks, 'amount' => round(min($raw, $amount * (float) $cap / 100), 2)];
    }
}
