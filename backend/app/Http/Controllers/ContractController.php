<?php
namespace App\Http\Controllers;

use App\Support\Cautions;
use App\Support\ComponentTree;
use App\Support\Ctx;
use App\Support\Dates;
use App\Support\DecompteLedger;
use App\Support\GoodsProcedure;
use App\Support\Loader;
use App\Support\Metrics;
use App\Support\Notifier;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ContractController extends Controller
{
    private const AEP = 'Système d’AEP';

    /**
     * Marché de biens : procédure, origine, échéancier indicatif et pénalité de retard. Le formulaire préremplit les taux du
     * document et ils restent modifiables. Un ancien contrat de biens modifié sans procédure garde les règles habituelles.
     */
    private function goodsFields(Request $r, string $category, float $initialRate, array &$e, ?array $c = null): array
    {
        $blank = ['goods_procedure' => null, 'goods_origin' => null, 'payment_schedule' => null, 'delay_penalty_rate' => null, 'delay_penalty_cap' => null, 'goods_min_amount' => null, 'goods_max_amount' => null];
        if ($category !== 'BIENS') return $blank;
        $procedure = trim((string) $r->input('goods_procedure', $c['goods_procedure'] ?? ''));
        if ($procedure === '' && $c) return $blank;
        if (!GoodsProcedure::label($procedure)) {
            $e['goods_procedure'] = 'Sélectionnez la procédure du marché : BAD, nationale ou fournitures de bureau.';
            return $blank;
        }
        if (GoodsProcedure::stockOnly($procedure)) {
            // Montants d'une commande : simple clause du contrat, sans effet sur le suivi.
            $min = Dates::decimal($r->input('goods_min_amount', $c['goods_min_amount'] ?? GoodsProcedure::OFFICE_MIN_AMOUNT));
            $max = Dates::decimal($r->input('goods_max_amount', $c['goods_max_amount'] ?? GoodsProcedure::OFFICE_MAX_AMOUNT));
            if ($min === null || $min < 0) $e['goods_min_amount'] = 'Saisissez un montant minimum valide, par exemple 500,00.';
            if ($max === null || $max <= 0) $e['goods_max_amount'] = 'Saisissez un montant maximum valide, par exemple 5500,00.';
            elseif ($min !== null && $min > $max) $e['goods_max_amount'] = 'Le montant maximum doit être supérieur ou égal au montant minimum.';
            return ['goods_procedure' => $procedure, 'goods_min_amount' => $min, 'goods_max_amount' => $max] + $blank;
        }
        $origin = trim((string) $r->input('goods_origin', $c['goods_origin'] ?? ''));
        if (!GoodsProcedure::originLabel($origin)) {
            $e['goods_origin'] = 'Indiquez la provenance des biens : étranger ou locale.';
            return ['goods_procedure' => $procedure] + $blank;
        }
        [$tranches, $error] = GoodsProcedure::schedule($procedure, $origin, (array) $r->input('payment_schedule', $c['payment_schedule'] ?? []), $initialRate);
        if ($error) $e['payment_schedule'] = $error;
        [$penaltyRate, $penaltyCap] = GoodsProcedure::defaultPenalty($procedure);
        if ($procedure === GoodsProcedure::NATIONAL) {
            $penaltyRate = Dates::decimal($r->input('delay_penalty_rate', $c['delay_penalty_rate'] ?? $penaltyRate));
            $penaltyCap = Dates::decimal($r->input('delay_penalty_cap', $c['delay_penalty_cap'] ?? $penaltyCap));
            if ($penaltyRate === null || $penaltyRate < 0 || $penaltyRate > 100) $e['delay_penalty_rate'] = 'Le taux de pénalité doit être compris entre 0 et 100 %.';
            if ($penaltyCap === null || $penaltyCap < 0 || $penaltyCap > 100) $e['delay_penalty_cap'] = 'Le plafond de pénalité doit être compris entre 0 et 100 %.';
        }
        return ['goods_procedure' => $procedure, 'goods_origin' => $origin, 'payment_schedule' => json_encode($tranches, JSON_UNESCAPED_UNICODE), 'delay_penalty_rate' => $penaltyRate, 'delay_penalty_cap' => $penaltyCap];
    }

    private function invalid(array $errors): void
    {
        throw ValidationException::withMessages($errors);
    }

    private function contractOr404(int $id): array
    {
        $c = Loader::contract(Ctx::projectId(), Ctx::phaseId(), $id, true);
        abort_unless($c && Ctx::canReadContract($c['goods_procedure'], $c['unit_id']), 404, 'Contrat introuvable.');
        return $c;
    }

    private function needManage(array $c): void
    {
        abort_unless(Ctx::canManageContract($c['goods_procedure'], $c['unit_id']), 403, GoodsProcedure::stockOnly($c['goods_procedure'])
            ? 'Ce contrat de fournitures de bureau est géré par la logistique de ' . ($c['unit_name'] ?: 'son unité') . '.' : 'Vous n’êtes pas habilité à gérer les contrats.');
    }

    private static function contractView(array $c, bool $withSites): array
    {
        return Views::contract($c, $withSites) + ['canManage' => (bool) Ctx::canManageContract($c['goods_procedure'] ?? null, $c['unit_id'] ?? null)];
    }

    public function index(Request $r)
    {
        // Les contrats de fournitures de bureau ne sont visibles que de leur unité et de la vue nationale.
        $all = array_values(array_filter(Loader::contracts(Ctx::projectId(), Ctx::phaseId(), null, false, true), fn ($c) => Ctx::canReadContract($c['goods_procedure'], $c['unit_id'])));
        $q = mb_strtolower(trim((string) $r->query('q', '')));
        if ($q !== '') {
            $all = array_values(array_filter($all, function ($c) use ($q) {
                $hay = mb_strtolower($c['number'] . ' ' . $c['company'] . ' ' . $c['category'] . ' ' . $c['province'] . ' ' . implode(' ', array_column($c['sites'], 'name')) . ' ' . implode(' ', array_column($c['sites'], 'province')));
                return str_contains($hay, $q);
            }));
        }
        // 5 contrats par page ; les compteurs par statut portent sur tous les contrats retenus, pas seulement sur la page.
        $size = max(1, min(7, (int) $r->query('size', 5)));
        [$page, $pages, $offset] = Views::paginate(count($all), (int) $r->query('page', 1), $size);
        $views = array_map(fn ($c) => self::contractView($c, true), $all);
        return [
            'data' => array_slice($views, $offset, $size),
            'meta' => ['page' => $page, 'pages' => $pages, 'total' => count($all), 'size' => $size],
            'statusCounts' => array_count_values(array_column(array_filter($views, fn ($v) => !$v['stockOnly']), 'status')),

        ];
    }

    /** Fiche complète : sert à la fois la page de gestion (Contrats) et la consultation en lecture seule (Dashboard). */
    public function show(int $id)
    {
        $c = $this->contractOr404($id);
        $today = Dates::today();
        $prev = Dates::addMonths($today, -1);
        $docs = DB::table('contract_documents as d')->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by')->where('d.contract_id', $id)->orderByDesc('d.id')
            ->select('d.*', 'u.full_name as uploader')->get()->map(fn ($d) => [
                'id' => $d->id, 'fileName' => $d->original_name, 'size' => (int) $d->file_size, 'uploadedAt' => (string) $d->created_at, 'uploadedBy' => (string) $d->uploader,
            ])->values();
        $sitesById = [];
        foreach ($c['sites'] as $s) $sitesById[$s['id']] = $s['name'];
        $entries = array_reverse($c['entries']);
        $adv = Views::startupAdvance($c);
        return [
            'contract' => self::contractView($c, true),
            'guarantee' => $c['guarantee'] ? Views::guarantee($c['guarantee']) : null,
            'amendments' => array_reverse($c['amendments']),
            'renewals' => array_reverse($c['renewals']),
            'goodsRules' => GoodsProcedure::describe($c['goods_procedure'], $c['goods_origin']),
            'documents' => $docs,
            'payments' => array_map(fn ($p) => ['id' => $p['id'], 'date' => $p['date'], 'amount' => $p['amount'], 'reference' => $p['reference'], 'observation' => $p['observation']], array_reverse($c['payments'])),
            'entries' => array_map(fn ($e) => ['id' => $e['id'], 'date' => $e['date'], 'site' => $e['site_id'] ? ($sitesById[$e['site_id']] ?? '—') : (!empty($e['installation']) ? 'Installation de chantier' : 'Contrat'), 'progress' => $e['progress'], 'realization' => $e['realization'], 'observation' => $e['observation']], array_slice($entries, 0, 30)),
            'startupAdvance' => $adv ? ['date' => $adv['date'], 'amount' => $adv['amount'], 'reference' => $adv['reference']] : null,
            'history' => Metrics::history($c, $today),
            'siteEvolution' => Metrics::siteEvolution($c, $prev, $today),
            'sitePrevLabel' => Dates::fr($prev), 'siteCurLabel' => Dates::fr($today),
        ];
    }

    // ------------------------------------------------------------------ Création / modification
    private function categoryFlags(string $category): array
    {
        $cat = mb_strtoupper(trim($category));
        return [$cat === 'TRAVAUX', in_array($cat, ['SERVICE', 'BIENS', 'BIEN'], true)];
    }

    private function workType(string $category, string $name, bool $custom): void
    {
        DB::table('work_types')->insertOrIgnore(['project_id' => Ctx::projectId(), 'category' => $category, 'name' => $name, 'is_custom' => $custom, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function cleanSites($raw): array
    {
        $out = [];
        foreach ((array) $raw as $s) {
            $name = trim((string) ($s['name'] ?? ''));
            if ($name === '') continue;
            // Montant prévu du site (avancement pondéré) : vide pour un ancien contrat suivi par la moyenne de ses sites.
            $rawAmount = trim((string) ($s['amount'] ?? ''));
            $amount = $rawAmount === '' ? null : Dates::decimal($rawAmount);
            $out[] = [
                'id' => (int) ($s['id'] ?? 0), 'name' => $name, 'province' => trim((string) ($s['province'] ?? '')), 'work_type' => trim((string) ($s['work_type'] ?? '')),
                'description' => trim((string) ($s['description'] ?? '')), 'quantity' => max(0, (float) (Dates::decimal($s['quantity'] ?? 1) ?? 1)) ?: 1,
                'amount' => $amount === null || $amount < 0 ? null : round($amount, 2), 'amount_invalid' => $rawAmount !== '' && ($amount === null || $amount < 0),
                'amount_given' => is_array($s) && array_key_exists('amount', $s), // absent : un site enregistré garde son montant

            ];
        }
        return $out;
    }

    /**
     * Entreprise de la catégorie du contrat (décision du 18/11/2026). Acceptées aussi : une entreprise sans catégorie
     * (enregistrée avant cette décision) et, à la modification, l'entreprise que le contrat a déjà.
     */
    private function companyCategoryError(int $companyId, string $category, ?int $currentCompany = null): ?string
    {
        if (!CompanyController::hasCategory() || $companyId === $currentCompany) return null;
        $companyCategory = (string) DB::table('companies')->where('id', $companyId)->value('category');
        if ($companyCategory === '' || $companyCategory === $category) return null;
        $labels = CompanyController::CATEGORIES;
        return 'Cette entreprise est enregistrée dans la catégorie ' . $labels[$companyCategory] . ' : choisissez une entreprise de la catégorie ' . $labels[$category] . '.';
    }

    /**
     * Rattachement à la matrice d'exécution, facultatif (décisions du 10 et du 16/11/2026) : une activité d'une composante
     * (Administration › Composantes), dont la composante est déduite ; à défaut, une composante seule (contrats rattachés
     * avant le détail par catégories et activités). Activité et composante doivent être actives, sauf si le contrat les a déjà.
     */
    private function componentFields(Request $r, array &$e, ?int $current = null, ?int $currentActivity = null): array
    {
        $ready = ComponentTree::ready();
        $activity = $ready ? ((int) $r->input('activity_id', 0) ?: null) : null;
        if ($activity) {
            $a = ComponentTree::activity(Ctx::projectId(), $activity);
            if (!$a || ($activity !== $currentActivity && (!$a->is_active || !$a->component_active))) {
                $e['activity_id'] = 'Choisissez une activité enregistrée et active d’une composante active du projet.';
                return ['component_id' => $current, 'activity_id' => $currentActivity];
            }
            return ['component_id' => (int) $a->component_id, 'activity_id' => $activity];
        }
        $id = (int) $r->input('component_id', 0) ?: null;
        if ($id && $id !== $current && !DB::table('project_components')->where('id', $id)->where('project_id', Ctx::projectId())->where('is_active', true)->exists()) {
            $e['component_id'] = 'Choisissez une composante enregistrée et active du projet.';
            $id = $current;
        }
        return ['component_id' => $id] + ($ready ? ['activity_id' => null] : []);
    }

    /**
     * Mode d'acquisition (décision du 22/11/2026) : AON, AOI, CF, ED, ou le texte d'un autre mode, saisi une fois avec le
     * contrat et repris dans les tableaux des cautions et des garanties. Obligatoire ; à la modification, un appel sans le
     * champ garde celui du contrat. Tant que la base n'est pas à jour (V40), il n'est ni demandé ni enregistré.
     */
    private function acquisitionMode(Request $r, array &$e, ?string $current = null, bool $creating = true): ?string
    {
        if (!Loader::hasAdvanceGuarantees()) return null;
        if (!$creating && !$r->has('acquisition_mode')) return $current;
        $mode = Cautions::modeFromInput((string) $r->input('acquisition_mode', ''), (string) $r->input('acquisition_mode_other', ''), $error);
        if ($error) $e['acquisition_mode'] = $error;
        return $mode;
    }

    /**
     * Garantie de bonne exécution corrigée dans la modification du contrat (décision du 25/11/2026), comme dans Finance ›
     * Garanties : n°, banque, dates d'émission et d'expiration (les siennes), observations, et le taux hors marché de biens
     * BAD ou national (dont le taux suit la procédure). Seulement avec « Gérer les garanties » ; prolongations et
     * renouvellements restent dans Finance › Garanties. Un appel sans ces champs garde la garantie telle quelle.
     */
    private function guaranteeEdit(Request $r, array &$e, int $pid, int $id, ?string $procedure, ?float &$newRate): ?array
    {
        if (!$r->has('guarantee_start_date') || !Ctx::can('can_manage_guarantees') || GoodsProcedure::stockOnly($procedure)) return null;
        $g = DB::table('performance_guarantees')->where('project_id', $pid)->where('contract_id', $id)->first();
        if (!$g) return null;
        $after = [
            'bank_name' => trim((string) $r->input('guarantee_bank_name', '')), 'account_number' => trim((string) $r->input('guarantee_account_number', '')),
            'start_date' => Dates::valid($r->input('guarantee_start_date')) ? Dates::part($r->input('guarantee_start_date')) : null,
            'end_date' => Dates::valid($r->input('guarantee_end_date')) ? Dates::part($r->input('guarantee_end_date')) : null,
            'observation' => trim((string) $r->input('guarantee_observation', '')),
        ];
        if ($after['bank_name'] === '') $e['guarantee_bank_name'] = 'La banque émettrice de la garantie est obligatoire.';
        if (!$after['start_date']) $e['guarantee_start_date'] = 'La date d’émission de la garantie est obligatoire.';
        if (!$after['end_date']) $e['guarantee_end_date'] = 'La date d’expiration de la garantie est obligatoire.';
        elseif ($after['start_date'] && $after['end_date'] <= $after['start_date']) $e['guarantee_end_date'] = 'La date d’expiration doit suivre la date d’émission.';
        if (!GoodsProcedure::governs($procedure) && $r->filled('guarantee_rate')) {
            $rate = Dates::decimal($r->input('guarantee_rate'));
            if ($rate === null || $rate <= 0 || $rate > 100) $e['guarantee_rate'] = 'Le taux de garantie doit être supérieur à 0 et inférieur ou égal à 100 %.';
            else $newRate = $rate;
        }
        $before = ['bank_name' => (string) $g->bank_name, 'account_number' => (string) $g->account_number, 'start_date' => Dates::part($g->start_date), 'end_date' => Dates::part($g->end_date), 'observation' => (string) $g->observation];
        $rateBefore = (float) $g->rate;
        $rateAfter = $newRate ?? $rateBefore;
        return ['id' => (int) $g->id, 'before' => $before + ['rate' => $rateBefore], 'after' => $after, 'auditAfter' => $after + ['rate' => $rateAfter],
            'changed' => $before != $after || abs($rateAfter - $rateBefore) > 0.0001];
    }

    /**
     * Avance sur demande d'un marché de biens BAD ou national saisie ou corrigée dans la modification du contrat (décision du
     * 25/11/2026), avec les contrôles de Finance › Paiements : n° de l'avance, date de la demande, caution (n°, banque, dates,
     * montant). Seulement avec « Gérer les paiements ». Sans demande et sans aucun champ rempli, rien n'est enregistré ; le
     * paiement, le rejet et l'annulation restent dans Paiements.
     */
    private function advanceRequestEdit(Request $r, array &$e, int $id, ?string $procedure, float $rate, float $amount, ?string $signedOn): ?array
    {
        if (!$r->has('advance_request_request_reference') || !Ctx::can('can_manage_payments') || !GoodsProcedure::governs($procedure) || $rate <= 0) return null;
        $in = [];
        foreach (ContractAdvanceController::FIELDS as $k) $in[$k] = $r->input("advance_request_{$k}");
        $current = DB::table('contract_advances')->where('contract_id', $id)->where('status', '!=', ContractAdvanceController::REJECTED)->orderByDesc('id')->first();
        if (!$current && !array_filter($in, fn ($v) => trim((string) $v) !== '')) return null;
        $advance = $current ? (float) $current->amount : round($amount * $rate / 100, 2);
        [$errors, $data] = ContractAdvanceController::check($in, $signedOn, $advance, $current && $current->status === ContractAdvanceController::PAID && $current->paid_date ? Dates::part($current->paid_date) : null);
        foreach ($errors as $k => $m) $e["advance_request_{$k}"] = $m;
        if (!$current && ContractAdvanceController::blockedByDecomptes($id)) $e['advance_request_request_reference'] = 'Un décompte est déjà enregistré : l’avance ne peut plus être demandée pour ce contrat.';
        return ['current' => $current, 'data' => $data, 'amount' => $advance];
    }

    /** Marché de biens (décision du 25/11/2026) : AON impose la procédure nationale, AOI la procédure BAD. */
    private function checkModeProcedure(string $category, ?string $mode, ?string $procedure, array &$e): void
    {
        $expected = Cautions::procedureForMode($category, $mode, $procedure);
        if ($expected && $procedure !== $expected && !isset($e['goods_procedure'])) {
            $e['goods_procedure'] = 'Mode d’acquisition ' . trim((string) $mode) . ' : la procédure du marché est la ' . ($expected === GoodsProcedure::BAD ? 'procédure BAD' : 'procédure nationale') . '.';
        }
    }

    /** Le contrat a une avance de démarrage à la signature : ni service, ni fournitures de bureau, ni biens BAD ou national (avance sur demande). */
    private static function hasStartupAdvance(string $category, ?string $goodsProcedure, float $rate): bool
    {
        return $category !== 'SERVICE' && !GoodsProcedure::stockOnly($goodsProcedure) && !GoodsProcedure::governs($goodsProcedure) && $rate > 0;
    }

    /**
     * Caution d'avance de démarrage (décision du 22/11/2026), facultative et complétée plus tard : null quand tous ses champs
     * sont vides. Ses dates (date de la caution, date d'expiration) sont les siennes, distinctes de celles du marché.
     */
    private function advanceGuarantee(Request $r, array &$e, ?string $signature = null): ?array
    {
        $ref = trim((string) $r->input('advance_guarantee_reference', ''));
        $bank = trim((string) $r->input('advance_guarantee_bank', ''));
        $obs = trim((string) $r->input('advance_guarantee_observation', ''));
        $issueRaw = trim((string) $r->input('advance_guarantee_issue_date', ''));
        $endRaw = trim((string) $r->input('advance_guarantee_end_date', ''));
        if ($ref === '' && $bank === '' && $obs === '' && $issueRaw === '' && $endRaw === '') return null;
        if (!Loader::hasAdvanceGuarantees()) {
            $e['advance_guarantee_reference'] = 'La base n’est pas encore à jour (migration du 22/11/2026 ou script V40) : la caution d’avance ne peut pas être enregistrée.';
            return null;
        }
        if ($ref === '') $e['advance_guarantee_reference'] = 'Saisissez la référence de la caution, ou videz ses champs pour la compléter plus tard.';
        elseif (mb_strlen($ref) > 120) $e['advance_guarantee_reference'] = 'La référence ne doit pas dépasser 120 caractères.';
        if (mb_strlen($bank) > 191) $e['advance_guarantee_bank'] = 'Le nom de la banque ne doit pas dépasser 191 caractères.';
        if (mb_strlen($obs) > 500) $e['advance_guarantee_observation'] = 'L’observation ne doit pas dépasser 500 caractères.';
        $issue = $issueRaw === '' ? null : (Dates::valid($issueRaw) ? Dates::part($issueRaw) : false);
        $end = $endRaw === '' ? null : (Dates::valid($endRaw) ? Dates::part($endRaw) : false);
        if ($issue === false) $e['advance_guarantee_issue_date'] = 'La date de la caution n’est pas valide.';
        if ($end === false) $e['advance_guarantee_end_date'] = 'La date d’expiration n’est pas valide.';
        // L'avance se donne après la signature du contrat (décision du 23/11/2026) : sa caution n'est jamais datée d'avant.
        if ($issue && $signature && $issue < $signature) $e['advance_guarantee_issue_date'] = 'L’avance de démarrage vient après la signature du contrat : la date de la caution ne peut pas être antérieure au ' . Dates::fr($signature) . '.';
        if ($issue && $end && $end < $issue) $e['advance_guarantee_end_date'] = 'La date d’expiration doit suivre la date de la caution.';
        return ['reference' => $ref, 'bank_name' => $bank !== '' ? $bank : null, 'issue_date' => $issue ?: null, 'end_date' => $end ?: null, 'observation' => $obs !== '' ? $obs : null];
    }

    /** Enregistre la caution d'avance du contrat ; null la retire. */
    private function saveAdvanceGuarantee(int $pid, int $contractId, ?array $g): void
    {
        if (!Loader::hasAdvanceGuarantees()) return;
        if ($g === null) {
            DB::table('advance_guarantees')->where('contract_id', $contractId)->delete();
            return;
        }
        if (DB::table('advance_guarantees')->where('contract_id', $contractId)->exists()) {
            DB::table('advance_guarantees')->where('contract_id', $contractId)->update($g + ['updated_at' => now()]);
        } else {
            DB::table('advance_guarantees')->insert($g + ['project_id' => $pid, 'contract_id' => $contractId, 'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Coût de l'installation de chantier et des travaux préparatoires : 0 si le champ est vide. */
    private function installationCost(Request $r, array &$e): float
    {
        $raw = trim((string) $r->input('installation_cost', ''));
        if ($raw === '') return 0.0;
        $v = Dates::decimal($raw);
        if ($v === null || $v < 0) {
            $e['installation_cost'] = 'Saisissez un coût valide, par exemple 25000,00 (0 si le contrat n’en prévoit pas).';
            return 0.0;
        }
        return round($v, 2);
    }

    /**
     * Somme des montants prévus des sites (décision du 10/11/2026) : chaque site doit avoir un montant supérieur à zéro, car
     * le montant du contrat est l'installation de chantier plus la somme des sites, et il pondère l'avancement physique.
     */
    private function siteAmounts(array $sites, array &$e): float
    {
        $sum = 0.0;
        foreach ($sites as $s) {
            if (!empty($s['amount_invalid'])) $e['sites'] = "Le montant prévu du site « {$s['name']} » n’est pas valide : saisissez par exemple 150000,00.";
            elseif ($s['amount'] === null || $s['amount'] <= 0) $e['sites'] = "Saisissez le montant prévu du site « {$s['name']} » : le montant du contrat est la somme des sites et de l’installation de chantier.";
            else $sum += $s['amount'];
        }
        return round($sum, 2);
    }

    public function store(Request $r)
    {
        // Fournitures de bureau : créées par la logistique, pour son unité. Les autres contrats : « Gérer les contrats ».
        $officeUnit = null;
        if (trim((string) $r->input('category', '')) === 'BIENS' && GoodsProcedure::stockOnly($r->input('goods_procedure'))) {
            Ctx::need('can_manage_stock');
            $officeUnit = (int) Ctx::user()->organizational_unit_id;
            abort_unless($officeUnit, 422, 'Votre compte n’est rattaché à aucune unité : demandez votre affectation au super-administrateur.');
            Ctx::needUnit($officeUnit);
        } else {
            Ctx::need('can_manage_contracts');
        }
        $pid = Ctx::projectId();
        $e = [];
        $number = trim((string) $r->input('reference', ''));
        if ($number === '') $e['reference'] = 'Le numéro du contrat est obligatoire.';
        elseif (DB::table('contracts')->where('project_id', $pid)->where('reference', $number)->exists()) $e['reference'] = 'Ce numéro de contrat existe déjà.';
        $companyId = (int) $r->input('company_id');
        if (!DB::table('companies')->where('id', $companyId)->where('project_id', $pid)->exists()) $e['company_id'] = 'Sélectionnez une entreprise du projet.';
        $category = trim((string) $r->input('category', ''));
        if (!in_array($category, ['TRAVAUX', 'BIENS', 'SERVICE'], true)) $e['category'] = 'Sélectionnez une catégorie.';
        elseif (!isset($e['company_id']) && ($msg = $this->companyCategoryError($companyId, $category))) $e['company_id'] = $msg;
        [$isWorks, $isMulti] = $this->categoryFlags($category);
        $start = Dates::valid($r->input('start_date')) ? Dates::part($r->input('start_date')) : null;
        if (!$start) $e['start_date'] = 'La date de démarrage est obligatoire.';
        $signature = Dates::valid($r->input('signature_date')) ? Dates::part($r->input('signature_date')) : ($start ?: Dates::today());
        $duration = max(1, (int) $r->input('duration_months', 12));
        // Fournitures de bureau : pas de montant de contrat, seulement les montants minimum et maximum d'une commande.
        // Les autres contrats : montant = installation de chantier + somme des montants prévus des sites (calculé plus bas).
        $isOffice = $category === 'BIENS' && GoodsProcedure::stockOnly($r->input('goods_procedure'));
        // Coût d'installation de chantier et travaux préparatoires : contrats de TRAVAUX seulement.
        $installation = $category === 'TRAVAUX' ? $this->installationCost($r, $e) : null;
        $amount = 0.0;
        // Un contrat de SERVICE ou de fournitures de bureau n'a ni avance de démarrage ni garantie de bonne exécution.
        $isService = $category === 'SERVICE';
        $noGuarantee = $isService || ($category === 'BIENS' && GoodsProcedure::stockOnly($r->input('goods_procedure')));
        $initialRate = $noGuarantee ? 0.0 : (Dates::decimal($r->input('initial_financing_rate', 0)) ?? 0);
        if ($initialRate < 0 || $initialRate > 100) $e['initial_financing_rate'] = 'Le taux de l’avance de démarrage doit être compris entre 0 et 100 %.';
        $gRate = Dates::decimal($r->input('guarantee_rate', 5)) ?? 5;
        if (!$noGuarantee && ($gRate <= 0 || $gRate > 100)) $e['guarantee_rate'] = 'Le taux de garantie doit être supérieur à 0 et inférieur ou égal à 100 %.';
        $guaranteeBank = trim((string) $r->input('guarantee_bank_name', ''));
        if (!$noGuarantee && $guaranteeBank === '') $e['guarantee_bank_name'] = 'La banque émettrice de la garantie est obligatoire.';
        $end = $start ? Dates::addMonths($start, $duration) : null;
        // Garantie de bonne exécution (décision du 22/11/2026, à la place de celle du 11/11/2026) : date d'émission et date
        // d'expiration propres, distinctes des dates du marché ; sans dates transmises, celles du contrat.
        $gStart = Dates::valid($r->input('guarantee_start_date')) ? Dates::part($r->input('guarantee_start_date')) : $start;
        $gEnd = Dates::valid($r->input('guarantee_end_date')) ? Dates::part($r->input('guarantee_end_date')) : $end;
        if (!$noGuarantee && $gStart && $gEnd && $gEnd < $gStart) $e['guarantee_end_date'] = 'La date d’expiration de la garantie doit suivre sa date d’émission.';
        $mode = $this->acquisitionMode($r, $e);

        $provinces = array_values(array_unique(array_filter(array_map('trim', (array) $r->input('selected_provinces', [])))));
        $mainProvince = trim((string) $r->input('province', ''));
        if ($isMulti) {
            if (!$provinces) $e['province'] = 'Sélectionnez au moins une province d’exécution pour un contrat BIEN ou SERVICE.';
            else $mainProvince = $provinces[0];
        } elseif ($mainProvince === '') $e['province'] = 'La province est obligatoire.';

        $sites = $this->cleanSites($r->input('sites', []));
        if (!$sites) $e['sites'] = 'Ajoutez au moins un site d’exécution.';
        elseif (!$isOffice) {
            $amount = round((float) $installation + $this->siteAmounts($sites, $e), 2);
            if (!isset($e['sites']) && !isset($e['installation_cost']) && $amount <= 0) $e['amount'] = 'Le montant du contrat (installation de chantier + sites) doit être supérieur à zéro.';
        }
        $workNames = [];
        if ($isWorks) {
            $workNames = $r->input('work_mode') === 'Lourd' ? [self::AEP] : array_map('trim', (array) $r->input('selected_work_types', []));
            $workNames = array_values(array_unique(array_filter($workNames)));
        } else {
            $workNames = array_values(array_unique(array_filter(array_column($sites, 'work_type'))));
        }
        $goods = $this->goodsFields($r, $category, (float) $initialRate, $e) + ['organizational_unit_id' => $officeUnit] + $this->componentFields($r, $e);
        if (Loader::hasAdvanceGuarantees()) $goods['acquisition_mode'] = $mode;
        $this->checkModeProcedure($category, $mode, $goods['goods_procedure'] ?? null, $e);
        // Caution d'avance de démarrage : seulement si le contrat a une avance à la signature.
        $caution = self::hasStartupAdvance($category, $goods['goods_procedure'] ?? null, (float) $initialRate) ? $this->advanceGuarantee($r, $e, $signature) : null;
        if ($isMulti && $provinces && $sites) {
            $entered = array_unique(array_filter(array_column($sites, 'province')));
            if (array_diff($provinces, $entered)) $e['sites'] = 'Ajoutez au moins un site pour chaque province sélectionnée.';
        }
        if ($e) $this->invalid($e);

        $id = DB::transaction(function () use ($caution, $noGuarantee, $goods, $r, $pid, $number, $companyId, $category, $isMulti, $isOffice, $start, $end, $signature, $duration, $amount, $installation, $initialRate, $gRate, $gStart, $gEnd, $mainProvince, $sites, $workNames, $guaranteeBank) {
            $cid = DB::table('contracts')->insertGetId([
                'project_id' => $pid, 'phase_id' => Ctx::phaseId(), 'company_id' => $companyId, 'reference' => $number, 'title' => trim((string) $r->input('title', '')) ?: null,
                'province' => $mainProvince, 'category' => $category, 'amount' => $amount, 'installation_cost' => $installation, 'start_date' => $start, 'end_date' => $end, 'signature_date' => $signature,
                'duration_months' => $duration, 'initial_financing_rate' => $initialRate, 'activities' => trim((string) $r->input('activities', '')),
                'work_type_text' => implode('; ', $workNames), 'completed' => false, 'created_at' => now(), 'updated_at' => now(),
            ] + $goods);
            $rate = $gRate;
            if (!$noGuarantee) DB::table('performance_guarantees')->insert([
                'project_id' => $pid, 'contract_id' => $cid, 'company_id' => $companyId,
                'bank_name' => $guaranteeBank,
                'account_number' => trim((string) $r->input('guarantee_account_number', '')) ?: null,
                'rate' => $rate, 'amount' => round($amount * $rate / 100, 2), 'start_date' => $gStart, 'end_date' => $gEnd,
                'observation' => trim((string) $r->input('guarantee_observation', '')) ?: null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($caution) $this->saveAdvanceGuarantee($pid, $cid, $caution);
            foreach ($sites as $s) {
                $wt = $s['work_type'] !== '' ? $s['work_type'] : ($workNames[0] ?? '');
                if ($wt !== '') $this->workType($category, $wt, $wt !== self::AEP);
                DB::table('contract_sites')->insert([
                    'contract_id' => $cid, 'province' => $isMulti ? ($s['province'] ?: $mainProvince) : $mainProvince, 'work_type' => $wt, 'name' => $s['name'],
                    'description' => $s['description'] !== '' ? $s['description'] : $wt, 'quantity' => $s['quantity'], 'amount' => $isOffice ? null : $s['amount'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return $cid;
        });
        $detail = $isOffice ? '' : ' Montant : ' . number_format($amount, 2, ',', ' ') . ' USD, dont installation de chantier ' . number_format((float) $installation, 2, ',', ' ') . ' USD.';
        if ($mode) $detail .= " Mode d’acquisition : {$mode}.";
        if ($caution) $detail .= " Caution d’avance n° {$caution['reference']}" . ($caution['bank_name'] ? " ({$caution['bank_name']})" : '') . '.';
        Ctx::audit('CREATION', 'Contract', $id, "Contrat {$number} créé avec " . count($sites) . ' site(s).' . $detail);
        // Le suivi commence : les comptes choisis par le super-admin sont prévenus. Une fourniture de bureau ne se suit que par le stock.
        if (!$officeUnit) {
            $company = DB::table('companies')->where('id', $companyId)->value('name');
            Notifier::send(Notifier::holders($pid, 'can_follow_new_contracts'), 'NOUVEAU_CONTRAT', "Nouveau contrat {$number} — suivi à commencer",
                "Le contrat {$number} ({$company}) vient d’être enregistré. Commencez le suivi de son avancement physique et financier.", "/contracts/{$id}", 'Contract', $id);
        }
        return response()->json(['id' => $id, 'message' => 'Contrat créé.'], 201);
    }

    public function update(Request $r, int $id)
    {
        $pid = Ctx::projectId();
        $c = $this->contractOr404($id);
        $this->needManage($c);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut plus être modifié.');
        $e = [];
        $number = trim((string) $r->input('reference', ''));
        if ($number === '') $e['reference'] = 'Le numéro du contrat est obligatoire.';
        elseif (DB::table('contracts')->where('project_id', $pid)->where('reference', $number)->where('id', '!=', $id)->exists()) $e['reference'] = 'Ce numéro de contrat existe déjà.';
        $companyId = (int) $r->input('company_id');
        if (!DB::table('companies')->where('id', $companyId)->where('project_id', $pid)->exists()) $e['company_id'] = 'Sélectionnez une entreprise du projet.';
        $category = trim((string) $r->input('category', $c['category']));
        if (!in_array($category, ['TRAVAUX', 'BIENS', 'SERVICE'], true)) $e['category'] = 'Sélectionnez une catégorie.';
        elseif (!isset($e['company_id']) && ($msg = $this->companyCategoryError($companyId, $category, (int) $c['company_id']))) $e['company_id'] = $msg;
        [$isWorks, $isMulti] = $this->categoryFlags($category);
        $start = Dates::valid($r->input('start_date')) ? Dates::part($r->input('start_date')) : null;
        if (!$start) $e['start_date'] = 'La date de démarrage est obligatoire.';
        $duration = max(1, (int) $r->input('duration_months', $c['duration_months'] ?: 12));
        // Le montant est fixé plus bas, avec les sites : calculé pour un contrat pondéré, saisi pour un ancien contrat.
        $amount = (float) $c['amount'];
        $installation = $c['installation_cost'];
        $initialRate = $category === 'SERVICE' || ($category === 'BIENS' && GoodsProcedure::stockOnly($r->input('goods_procedure', $c['goods_procedure']))) ? 0.0 : (Dates::decimal($r->input('initial_financing_rate', 0)) ?? 0);
        if ($initialRate < 0 || $initialRate > 100) $e['initial_financing_rate'] = 'Le taux de l’avance de démarrage doit être compris entre 0 et 100 %.';
        $mainProvince = trim((string) $r->input('province', $c['province']));
        $goods = $this->goodsFields($r, $category, (float) $initialRate, $e, $c);
        // Entrer dans les fournitures de bureau ou en sortir demande les deux habilitations ; l'unité est celle du compte.
        $wasOffice = GoodsProcedure::stockOnly($c['goods_procedure']);
        $isOffice = GoodsProcedure::stockOnly($goods['goods_procedure']);
        if ($wasOffice !== $isOffice) {
            Ctx::need('can_manage_contracts');
            Ctx::need('can_manage_stock');
        }
        $goods['organizational_unit_id'] = $isOffice ? ($wasOffice ? $c['unit_id'] : ((int) Ctx::user()->organizational_unit_id ?: null)) : null;
        // Activité et composante : celles du formulaire ; un appel sans ces champs garde celles du contrat.
        $goods += $r->has('component_id') || $r->has('activity_id') ? $this->componentFields($r, $e, $c['component_id'], $c['activity_id'] ?? null) : ['component_id' => $c['component_id']];
        if (Loader::hasAdvanceGuarantees()) $goods['acquisition_mode'] = $this->acquisitionMode($r, $e, $c['acquisition_mode'] ?: null, false);
        // Un ancien contrat qui ne respecte pas encore la règle reste modifiable tant que ni le mode ni la procédure ne changent.
        if ((string) ($goods['acquisition_mode'] ?? $c['acquisition_mode']) !== (string) $c['acquisition_mode'] || (string) ($goods['goods_procedure'] ?? '') !== (string) $c['goods_procedure'] || $category !== $c['category']) {
            $this->checkModeProcedure($category, $goods['acquisition_mode'] ?? $c['acquisition_mode'], $goods['goods_procedure'] ?? null, $e);
        }
        // Caution d'avance : celle du formulaire ; un appel sans ses champs garde celle du contrat. Plus d'avance à la signature
        // (service, fournitures de bureau, biens BAD ou national, taux à 0) : la caution est retirée.
        $startup = self::hasStartupAdvance($category, $goods['goods_procedure'] ?? null, (float) $initialRate);
        $touchCaution = !$startup || $r->has('advance_guarantee_reference');
        // Date de signature du formulaire, à défaut celle du contrat, à défaut le démarrage (comme à l'enregistrement plus bas).
        $signedOn = Dates::valid($r->input('signature_date')) ? Dates::part($r->input('signature_date')) : ($c['signature_date'] ?: $start);
        $caution = $startup && $r->has('advance_guarantee_reference') ? $this->advanceGuarantee($r, $e, $signedOn) : null;
        // Une fois une avance demandée ou un décompte saisi, les règles du marché ne changent plus : les lignes en dépendent.
        $goodsInUse = DB::table('contract_decomptes')->where('contract_id', $id)->where('status', '!=', DecompteLedger::REJECTED)->exists() || DB::table('contract_advances')->where('contract_id', $id)->where('status', '!=', 'REJETEE')->exists();
        $goodsTouched = GoodsProcedure::governs($c['goods_procedure']) || GoodsProcedure::governs($goods['goods_procedure']);
        if ($goodsInUse && $goodsTouched && ($goods['goods_procedure'] !== $c['goods_procedure'] || $goods['goods_origin'] !== $c['goods_origin'] || abs((float) $initialRate - (float) $c['initial_rate']) > 0.001
            || abs((float) $goods['delay_penalty_rate'] - (float) $c['delay_penalty_rate']) > 0.0001 || abs((float) $goods['delay_penalty_cap'] - (float) $c['delay_penalty_cap']) > 0.001)) {
            $e['goods_procedure'] = 'Une demande d’avance ou un décompte existe déjà : la procédure, la provenance, l’avance et la pénalité ne peuvent plus changer.';
        }
        if (GoodsProcedure::stockOnly($goods['goods_procedure']) && !GoodsProcedure::stockOnly($c['goods_procedure']) && ($goodsInUse || $c['payments'] || $c['entries'])) {
            $e['goods_procedure'] = 'Ce contrat a déjà des paiements, des décomptes ou des avancements : il ne peut pas passer en fournitures de bureau.';
        }
        // Passer des fournitures de bureau à une procédure : la garantie, absente jusque-là, devient obligatoire.
        $newGuarantee = null;
        if (GoodsProcedure::stockOnly($c['goods_procedure']) && GoodsProcedure::governs($goods['goods_procedure']) && !DB::table('performance_guarantees')->where('contract_id', $id)->exists()) {
            $newGuarantee = ['bank_name' => trim((string) $r->input('guarantee_bank_name', '')), 'account_number' => trim((string) $r->input('guarantee_account_number', '')) ?: null, 'rate' => Dates::decimal($r->input('guarantee_rate'))];
            if ($newGuarantee['bank_name'] === '') $e['guarantee_bank_name'] = 'La banque émettrice de la garantie est obligatoire.';
            if ($newGuarantee['rate'] === null || $newGuarantee['rate'] <= 0 || $newGuarantee['rate'] > 100) $e['guarantee_rate'] = 'Le taux de garantie doit être supérieur à 0 et inférieur ou égal à 100 %.';
        }
        // Le taux de la garantie se corrige avec la procédure, tant que le marché n'a pas commencé.
        $newGuaranteeRate = null;
        if (GoodsProcedure::governs($goods['goods_procedure']) && !$goodsInUse && $r->filled('guarantee_rate')) {
            $newGuaranteeRate = Dates::decimal($r->input('guarantee_rate'));
            if ($newGuaranteeRate === null || $newGuaranteeRate <= 0 || $newGuaranteeRate > 100) $e['guarantee_rate'] = 'Le taux de garantie doit être supérieur à 0 et inférieur ou égal à 100 %.';
        }

        $sites = $this->cleanSites($r->input('sites', []));
        $existing = array_column($c['sites'], 'id');
        foreach ($sites as $i => $s) {
            if ($s['id'] > 0 && !in_array($s['id'], $existing, true)) $e['sites'] = 'Un site transmis n’appartient pas à ce contrat.';
            $prov = $isMulti ? $s['province'] : $mainProvince;
            if ($prov === '') $e['sites'] = 'La province du site « ' . $s['name'] . ' » est obligatoire.';
        }

        // Montant du contrat (décision du 10/11/2026). Les sites enregistrés absents du formulaire gardent leur montant.
        $merged = [];
        foreach ($c['sites'] as $s) $merged[$s['id']] = ['name' => $s['name'], 'amount' => $s['amount'] ?? null];
        $added = [];
        foreach ($sites as $i => $s) {
            if ($s['id'] > 0 && !$s['amount_given']) $sites[$i]['amount'] = $s['amount'] = $merged[$s['id']]['amount'] ?? null;
            if ($s['id'] > 0) $merged[$s['id']] = $s;
            else $added[] = $s;
        }
        $merged = array_merge(array_values($merged), $added);
        $isWorksNow = $category === 'TRAVAUX';
        $weighted = !$isOffice && (($isWorksNow && ($c['installation_cost'] !== null || trim((string) $r->input('installation_cost', '')) !== '')) || Metrics::weighting($c) !== null
            || array_filter($merged, fn ($s) => $s['amount'] !== null || !empty($s['amount_invalid'])));
        if ($isOffice) {
            $installation = null;
        } elseif ($weighted) {
            // Contrat pondéré : montant = installation de chantier + somme des sites, plus les montants ajoutés par les renouvellements.
            // Coût d'installation : contrats de TRAVAUX seulement (vidé si le contrat change de catégorie).
            $installation = $isWorksNow ? $this->installationCost($r, $e) : null;
            $amount = round((float) $installation + $this->siteAmounts($merged, $e) + (float) $c['renewed_amount'], 2);
        } else {
            // Ancien contrat, sans montant par site : montant saisi, avancement = moyenne simple de ses sites.
            $amount = Dates::decimal($r->input('amount'));
            if ($amount === null || $amount < 0) $e['amount'] = 'Saisissez un montant valide, par exemple 1500000,50.';
            elseif ($amount < (float) $c['renewed_amount']) $e['amount'] = 'Le montant ne peut pas être inférieur aux montants ajoutés par les renouvellements (' . number_format((float) $c['renewed_amount'], 2, ',', ' ') . ' USD).';
        }
        if (!$isWorksNow) $installation = null;
        $guaranteeEdit = $this->guaranteeEdit($r, $e, $pid, $id, $goods['goods_procedure'] ?? null, $newGuaranteeRate);
        $advanceEdit = $this->advanceRequestEdit($r, $e, $id, $goods['goods_procedure'] ?? null, (float) $initialRate, (float) $amount, $signedOn);
        if ($e) $this->invalid($e);

        DB::transaction(function () use ($touchCaution, $caution, $goods, $newGuarantee, $newGuaranteeRate, $guaranteeEdit, $advanceEdit, $r, $pid, $id, $number, $companyId, $category, $isWorks, $isMulti, $isOffice, $start, $duration, $amount, $installation, $initialRate, $mainProvince, $sites, $c) {
            // Après un avenant ou un renouvellement, la fin ne se recalcule que si le démarrage ou la durée changent vraiment.
            $end = ($c['amendments'] || $c['renewals']) && $start === $c['start_date'] && $duration === (int) $c['duration_months'] ? $c['end_date'] : Dates::addMonths($start, $duration);
            $signature = Dates::valid($r->input('signature_date')) ? Dates::part($r->input('signature_date')) : ($c['signature_date'] ?: $start);
            foreach ($sites as $s) {
                $wt = $s['work_type'];
                if ($wt !== '') $this->workType($category, $wt, $wt !== self::AEP);
                $data = ['province' => $isMulti ? $s['province'] : $mainProvince, 'work_type' => $wt, 'name' => $s['name'], 'description' => $s['description'], 'quantity' => $s['quantity'],
                    'amount' => $isOffice ? null : $s['amount'], 'updated_at' => now()];
                if ($s['id'] > 0) DB::table('contract_sites')->where('id', $s['id'])->where('contract_id', $id)->update($data);
                else DB::table('contract_sites')->insert($data + ['contract_id' => $id, 'created_at' => now()]);
            }
            $types = array_values(array_unique(array_filter(DB::table('contract_sites')->where('contract_id', $id)->pluck('work_type')->all())));
            if ($isWorks && $r->has('selected_work_types')) $types = array_values(array_unique(array_filter(array_map('trim', (array) $r->input('selected_work_types')))));
            DB::table('contracts')->where('id', $id)->update([
                'reference' => $number, 'company_id' => $companyId, 'category' => $category, 'province' => $mainProvince, 'signature_date' => $signature,
                'start_date' => $start, 'duration_months' => $duration, 'end_date' => $end, 'amount' => $amount, 'installation_cost' => $installation, 'initial_financing_rate' => $initialRate,
                'activities' => trim((string) $r->input('activities', '')), 'title' => trim((string) $r->input('title', '')) ?: null, 'work_type_text' => implode('; ', $types), 'updated_at' => now(),
            ] + $goods);
            // Fournitures de bureau : plus de garantie de bonne exécution (le marché n'a pas commencé, sinon la procédure est figée).
            if (GoodsProcedure::stockOnly($goods['goods_procedure']) && !GoodsProcedure::stockOnly($c['goods_procedure'])) {
                DB::table('performance_guarantees')->where('project_id', $pid)->where('contract_id', $id)->delete();
            }
            if ($newGuarantee) DB::table('performance_guarantees')->insert($newGuarantee + [
                'project_id' => $pid, 'contract_id' => $id, 'company_id' => $companyId, 'amount' => round((float) $amount * $newGuarantee['rate'] / 100, 2), 'start_date' => $start, 'end_date' => $end, 'created_at' => now(), 'updated_at' => now(),
            ]);
            // La garantie reste liée au contrat : synchroniser son entreprise et son montant si le contrat change. Ses dates
            // d'émission et d'expiration sont les siennes (décision du 22/11/2026) : elles ne suivent plus celles du marché.
            $guarantees = DB::table('performance_guarantees')->where('project_id', $pid)->where('contract_id', $id)->get(['id', 'rate']);
            foreach ($guarantees as $guarantee) {
                $rate = $newGuaranteeRate ?? (float) $guarantee->rate;
                DB::table('performance_guarantees')->where('id', $guarantee->id)->update([
                    'company_id' => $companyId, 'rate' => $rate, 'amount' => round(((float) $amount - (float) $c['renewed_amount']) * $rate / 100, 2), 'updated_at' => now(),
                ]);
            }
            if ($touchCaution) $this->saveAdvanceGuarantee($pid, $id, $caution);
            if ($guaranteeEdit) DB::table('performance_guarantees')->where('id', $guaranteeEdit['id'])->update($guaranteeEdit['after'] + ['updated_at' => now()]);
            if ($advanceEdit && $advanceEdit['current']) {
                DB::table('contract_advances')->where('id', $advanceEdit['current']->id)->update(ContractAdvanceController::columns($advanceEdit['data']) + ['updated_at' => now()]);
            } elseif ($advanceEdit) {
                abort_if(DB::table('contract_advances')->where('contract_id', $id)->where('status', '!=', ContractAdvanceController::REJECTED)->exists(), 422, 'Une demande d’avance est déjà enregistrée pour ce contrat.');
                $advanceEdit['id'] = DB::table('contract_advances')->insertGetId(ContractAdvanceController::columns($advanceEdit['data']) + [
                    'contract_id' => $id, 'rate' => $initialRate, 'amount' => $advanceEdit['amount'], 'status' => ContractAdvanceController::REQUESTED,
                    'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        if ($guaranteeEdit && $guaranteeEdit['changed']) {
            Ctx::auditChange('MODIFICATION_GARANTIE', 'PerformanceGuarantee', $guaranteeEdit['id'], "Garantie du contrat {$number} modifiée avec le contrat.", $guaranteeEdit['before'], $guaranteeEdit['auditAfter']);
        }
        if ($advanceEdit && $advanceEdit['current']) {
            $after = ContractAdvanceController::columns($advanceEdit['data']);
            $before = [];
            foreach ($after as $k => $v) {
                $old = $advanceEdit['current']->{$k} ?? null;
                $before[$k] = $k === 'guarantee_amount' ? ($old === null ? null : round((float) $old, 2)) : (str_ends_with($k, '_date') && $old ? Dates::part($old) : $old);
            }
            if ($before != $after) {
                Ctx::auditChange('MODIFICATION', 'ContractAdvance', $advanceEdit['current']->id, 'Corrigée avec le contrat : ' . ContractAdvanceController::auditText($advanceEdit['data'], $number, (float) $advanceEdit['current']->amount), $before, $after);
            }
        } elseif ($advanceEdit) {
            Ctx::audit('CREATION', 'ContractAdvance', $advanceEdit['id'], ContractAdvanceController::auditText($advanceEdit['data'], $number, $advanceEdit['amount']));
        }
        Ctx::audit('MODIFICATION', 'Contract', $id, "Contrat {$number} modifié avec " . count($sites) . ' site(s) enregistré(s).');
        return ['message' => "Contrat {$number} modifié. Les informations des sites ont également été enregistrées."];
    }

    public function destroy(Request $r, int $id)
    {
        Ctx::need('can_delete_contracts');
        $reason = trim((string) $r->input('reason', ''));
        abort_if(mb_strlen($reason) < 5, 422, 'Une raison valable (minimum 5 caractères) est obligatoire avant suppression.');
        $c = $this->contractOr404($id);
        if (GoodsProcedure::stockOnly($c['goods_procedure'])) Ctx::needUnit((int) $c['unit_id']);
        foreach (DB::table('contract_documents')->where('contract_id', $id)->get() as $d) Storage::disk('local')->delete($d->storage_path);
        DB::table('contract_documents')->where('contract_id', $id)->delete();
        DB::table('contracts')->where('id', $id)->delete();
        Ctx::audit('SUPPRESSION', 'Contract', $id, "Contrat {$c['number']} supprimé. Motif : {$reason}");
        return ['message' => 'Contrat supprimé.'];
    }

    public function terminate(Request $r, int $id)
    {
        $c = $this->contractOr404($id);
        $this->needManage($c);
        $reason = trim((string) $r->input('reason', ''));
        abort_if(mb_strlen($reason) < 5, 422, 'Le motif de résiliation est obligatoire.');
        abort_if($c['terminated'], 422, 'Ce contrat est déjà résilié.');
        $date = Dates::valid($r->input('termination_date')) ? Dates::part($r->input('termination_date')) : Dates::today();
        DB::table('contracts')->where('id', $id)->update(['termination_date' => $date, 'termination_reason' => $reason, 'updated_at' => now()]);
        Ctx::audit('RESILIATION_CONTRAT', 'Contract', $id, "Contrat {$c['number']} résilié le " . Dates::fr($date) . ". Motif : {$reason}");
        return ['message' => 'Le contrat a été marqué comme résilié. L’opération est tracée dans le journal d’audit.'];
    }

    /**
     * Renouvellement d'un contrat de BIENS : même contrat, nouvelle période, montant ajouté et numéro à suffixe (144/R1).
     * Pas de garantie exigée pour la nouvelle période, pas de nouvelle avance ; l'historique reste dans contract_renewals.
     */
    public function renew(Request $r, int $id)
    {
        $c = $this->contractOr404($id);
        $this->needManage($c);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut pas être renouvelé.');
        abort_unless(in_array(strtoupper((string) $c['category']), ['BIENS', 'BIEN'], true), 422, 'Seul un contrat de biens peut être renouvelé.');
        abort_unless(GoodsProcedure::renewable((string) $c['category'], $c['goods_procedure']), 422, 'Un marché en procédure BAD ou nationale ne se renouvelle pas : le renouvellement est réservé aux fournitures de bureau.');
        $pid = Ctx::projectId();
        $e = [];
        $number = trim((string) $r->input('reference', ''));
        if ($number === '') $e['reference'] = 'Le numéro du contrat renouvelé est obligatoire.';
        elseif ($number === $c['number']) $e['reference'] = 'Le contrat renouvelé doit porter un nouveau numéro.';
        elseif (DB::table('contracts')->where('project_id', $pid)->where('reference', $number)->where('id', '!=', $id)->exists()) $e['reference'] = 'Ce numéro de contrat existe déjà.';
        $date = Dates::valid($r->input('date')) ? Dates::part($r->input('date')) : null;
        if (!$date) $e['date'] = 'La date du renouvellement est obligatoire.';
        $start = Dates::valid($r->input('period_start')) ? Dates::part($r->input('period_start')) : null;
        if (!$start) $e['period_start'] = 'Le début de la nouvelle période est obligatoire.';
        $months = (int) $r->input('months');
        if ($months <= 0) $e['months'] = 'La durée doit être supérieure à zéro.';
        $added = GoodsProcedure::stockOnly($c['goods_procedure']) ? 0.0 : Dates::decimal($r->input('added_amount'));
        if ($added === null || $added < 0) $e['added_amount'] = 'Saisissez un montant valide, par exemple 1500,75.';
        $reason = trim((string) $r->input('reason', ''));
        if (mb_strlen($reason) < 5) $e['reason'] = 'Le motif du renouvellement est obligatoire.';
        $end = $start && $months > 0 ? Dates::addMonths($start, $months) : null;
        if ($end && $c['end_date'] && $end <= $c['end_date']) $e['months'] = 'La nouvelle fin doit dépasser la fin actuelle du contrat (' . Dates::fr($c['end_date']) . ').';
        if ($e) $this->invalid($e);
        abort_if(DB::table('contract_decomptes')->where('contract_id', $id)->where('kind', DecompteLedger::RELEASE)->where('status', DecompteLedger::PENDING)->exists(), 422,
            'Un reversement de la retenue attend sa validation : traitez-le avant de renouveler le contrat.');

        $no = $c['renewal_no'] + 1;
        DB::transaction(function () use ($r, $c, $id, $no, $number, $date, $start, $end, $months, $added, $reason) {
            DB::table('contract_renewals')->insert([
                'contract_id' => $id, 'renewal_no' => $no, 'date' => $date, 'previous_reference' => $c['number'], 'new_reference' => $number, 'added_amount' => round($added, 2), 'previous_amount' => $c['amount'],
                'previous_end_date' => $c['end_date'], 'period_start' => $start, 'new_end_date' => $end, 'months' => $months,
                'last_line_no' => (int) DB::table('contract_decomptes')->where('contract_id', $id)->max('line_no'), 'reason' => $reason, 'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $duration = $c['start_date'] ? max(1, (int) round(Dates::daysBetween($c['start_date'], $end) / 30.4375)) : $months;
            DB::table('contracts')->where('id', $id)->update(['reference' => $number, 'amount' => round($c['amount'] + $added, 2), 'renewed_amount' => round($c['renewed_amount'] + $added, 2),
                'renewal_no' => $no, 'end_date' => $end, 'duration_months' => $duration, 'completed' => false, 'updated_at' => now()]);
        });
        Ctx::auditChange('RENOUVELLEMENT_CONTRAT', 'Contract', $id, "Contrat {$c['number']} renouvelé sous le numéro {$number} : du " . Dates::fr($start) . ' au ' . Dates::fr($end) . ', montant ajouté ' . number_format($added, 2, ',', ' ') . " USD. Motif : {$reason}",
            ['reference' => $c['number'], 'amount' => $c['amount'], 'end_date' => $c['end_date']], ['reference' => $number, 'amount' => round($c['amount'] + $added, 2), 'end_date' => $end]);
        return ['message' => "Contrat renouvelé sous le numéro {$number}, jusqu’au " . Dates::fr($end) . '.'];
    }

    public function amendment(Request $r, int $id)
    {
        $c = $this->contractOr404($id);
        $this->needManage($c);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut plus recevoir d’avenant.');
        $months = (int) $r->input('added_months');
        abort_if($months <= 0, 422, 'La durée supplémentaire doit être supérieure à zéro.');
        $reason = trim((string) $r->input('reason', ''));
        abort_if(mb_strlen($reason) < 5, 422, 'Le motif de l’avenant est obligatoire.');
        $date = Dates::valid($r->input('date')) ? Dates::part($r->input('date')) : Dates::today();
        $previous = $c['end_date'] ?: $date;
        $next = Dates::addMonths($previous, $months);
        $duration = $c['start_date'] ? max(1, (int) round(Dates::daysBetween($c['start_date'], $next) / 30.4375)) : max(1, $c['duration_months'] + $months);
        DB::transaction(function () use ($id, $date, $months, $previous, $next, $reason, $duration) {
            DB::table('contract_amendments')->insert([
                'contract_id' => $id, 'date' => $date, 'added_months' => $months, 'duration_days' => $months * 30, 'previous_end_date' => $previous, 'new_end_date' => $next,
                'reason' => $reason, 'created_by' => Ctx::user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('contracts')->where('id', $id)->update(['end_date' => $next, 'duration_months' => $duration, 'updated_at' => now()]);
        });
        Ctx::audit('AVENANT_CONTRAT', 'ContractAmendment', $id, 'Avenant du ' . Dates::fr($date) . " : +{$months} mois, nouvelle fin " . Dates::fr($next) . ". Motif : {$reason}");
        return ['message' => 'Avenant enregistré et nouvelle date de fin appliquée au contrat.'];
    }
}
