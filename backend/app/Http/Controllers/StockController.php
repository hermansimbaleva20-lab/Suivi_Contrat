<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\UnitScope;
use App\Models\OrganizationalUnit;
use App\Models\StockArticle;
use App\Models\StockMovement;
use App\Support\Ctx;
use App\Support\Dates;
use App\Support\StockImport;
use App\Support\StockLedger;
use App\Support\Xlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Logistique — stocks (TDR §6), sur le modèle des documents du projet : articles (« prix unitaires évalués ») et fiche
 * de stock par article et par unité. Chaque unité gère son stock de manière autonome : la coordination nationale tient
 * le catalogue commun (articles sans unité), chaque antenne ajoute ses propres articles. Deux façons d'alimenter le
 * catalogue : par contrat (import du fichier des articles du contrat) ou sans contrat, article par article.
 * Isolation : lecture limitée par Ctx::readableUnitIds(), écriture par Ctx::needUnit(). Le stock n'est pas filtré par
 * phase : il se poursuit d'une phase à l'autre.
 */
class StockController extends Controller
{
    use UnitScope;

    // ------------------------------------------------------------------ Périmètre

    /** Unité propriétaire des articles que crée le compte : NULL (catalogue commun) pour la coordination, sinon son antenne. */
    private function ownerUnitId(): ?int
    {
        return $this->holdsCommonReferentials() ? null : ((int) Ctx::user()->organizational_unit_id ?: null);
    }

    /** Ajouter des articles : tout gestionnaire de stock d'une unité (catalogue commun à la coordination, articles propres en antenne). */
    private function canManageCatalog(): bool
    {
        if (!Ctx::can('can_manage_stock')) return false;
        return $this->holdsCommonReferentials() || Ctx::canWriteUnit((int) Ctx::user()->organizational_unit_id);
    }

    /** Modifier un article : celui de son antenne, ou le catalogue commun pour la coordination. */
    private function canEditArticle(StockArticle $a): bool
    {
        if (!Ctx::can('can_manage_stock')) return false;
        return $a->organizational_unit_id === null ? $this->holdsCommonReferentials() : Ctx::canWriteUnit((int) $a->organizational_unit_id);
    }

    private function needCatalog(): void
    {
        abort_unless($this->canManageCatalog(), 403, 'Vous n’êtes pas habilité à ajouter des articles de stock.');
    }

    private function needEdit(StockArticle $a): void
    {
        abort_unless($this->canEditArticle($a), 403, $a->organizational_unit_id === null
            ? 'Le catalogue commun est tenu par la coordination nationale.' : 'Cet article appartient à une autre unité que la vôtre.');
    }

    /** Articles visibles : le catalogue commun et ceux des unités consultables. */
    private function articlesQuery()
    {
        $q = StockArticle::where('project_id', Ctx::projectId());
        $ids = Ctx::readableUnitIds();
        if ($ids !== null) $q->where(fn ($w) => $w->whereNull('organizational_unit_id')->orWhereIn('organizational_unit_id', $ids ?: [0]));
        return $q;
    }

    private function articleOr404(int $id): StockArticle
    {
        $a = $this->articlesQuery()->where('id', $id)->first();
        abort_unless($a, 404, 'Article introuvable.');
        return $a;
    }

    /** Une même désignation n'existe qu'une fois pour une unité, catalogue commun compris. */
    private function designationTaken(string $designation, ?int $owner, ?int $ignoreId = null): bool
    {
        $q = StockArticle::where('project_id', Ctx::projectId())->whereRaw('LOWER(designation) = ?', [mb_strtolower(trim($designation))]);
        if ($owner !== null) $q->where(fn ($w) => $w->whereNull('organizational_unit_id')->orWhere('organizational_unit_id', $owner));
        if ($ignoreId) $q->where('id', '!=', $ignoreId);
        return $q->exists();
    }

    // ------------------------------------------------------------------ Vues

    private static function articleView(StockArticle $a): array
    {
        return [
            'id' => $a->id, 'number' => $a->number, 'designation' => $a->designation, 'unit' => $a->unit,
            'packSize' => $a->pack_size, 'packUnit' => $a->pack_unit,
            'unitPrice' => (float) $a->unit_price, 'alertThreshold' => $a->alert_threshold, 'isActive' => (bool) $a->is_active,
        ];
    }

    /** EMPTY : jamais approvisionné ; OUT : rupture ; LOW : au seuil d'alerte ou en dessous ; OK. */
    private static function level(StockArticle $a, float $stock, int $moves): string
    {
        if ($moves === 0) return 'EMPTY';
        if ($stock <= 0) return 'OUT';
        if ($a->alert_threshold !== null && $stock <= (float) $a->alert_threshold) return 'LOW';
        return 'OK';
    }

    private static function ledgerRow(object $m): array
    {
        return [
            'id' => (int) $m->id, 'date' => Dates::part($m->movement_date), 'type' => $m->type, 'quantity' => (float) $m->quantity,
            'cancelled' => $m->cancelled_at !== null, 'origin' => $m->origin_destination, 'observation' => (string) $m->observation,
            'createdBy' => $m->created_by_name ?? null, 'cancelReason' => $m->cancel_reason, 'cancelledBy' => $m->cancelled_by_name ?? null,
            'cancelledAt' => $m->cancelled_at ? substr((string) $m->cancelled_at, 0, 16) : null,
            'operationNo' => $m->operation_no === null ? null : (int) $m->operation_no, 'company' => $m->company_name ?? null, 'reference' => $m->reference,
        ];
    }

    private static function lineView(array $m): array
    {
        return [
            'id' => $m['id'], 'date' => $m['date'], 'type' => $m['type'], 'originDestination' => $m['origin'],
            'entry' => $m['type'] === StockLedger::ENTREE ? $m['quantity'] : null, 'exit' => $m['type'] === StockLedger::SORTIE ? $m['quantity'] : null,
            'stock' => $m['balance'], 'observation' => $m['observation'], 'createdBy' => $m['createdBy'],
            'isCancelled' => $m['cancelled'], 'cancelReason' => $m['cancelReason'], 'cancelledBy' => $m['cancelledBy'], 'cancelledAt' => $m['cancelledAt'],
            'operationNo' => $m['operationNo'], 'company' => $m['company'], 'reference' => $m['reference'],
        ];
    }

    /** Mouvements d'un article dans une unité, au format de StockLedger. */
    private function ledger(int $articleId, int $unitId): array
    {
        return DB::table('stock_movements as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by')
            ->leftJoin('users as c', 'c.id', '=', 'm.cancelled_by')
            ->leftJoin('companies as co', 'co.id', '=', 'm.company_id')
            ->where('m.project_id', Ctx::projectId())->where('m.stock_article_id', $articleId)->where('m.organizational_unit_id', $unitId)
            ->select('m.*', 'u.full_name as created_by_name', 'c.full_name as cancelled_by_name', 'co.name as company_name')
            ->get()->map(fn ($m) => self::ledgerRow($m))->all();
    }

    private static function qty(float $q): string
    {
        return rtrim(rtrim(number_format($q, 2, ',', ' '), '0'), ',');
    }

    // ------------------------------------------------------------------ Consultation

    /** État du stock : une ligne par article, pour une unité ou pour l'ensemble des unités consultables. */
    public function overview(Request $r)
    {
        $pid = Ctx::projectId();
        $units = $this->units();
        $unit = $this->requestedUnit($r, $units, false);
        $unitIds = $unit ? [$unit->id] : $units->pluck('id')->all();

        $stocks = DB::table('stock_movements')->where('project_id', $pid)->whereNull('cancelled_at')->whereIn('organizational_unit_id', $unitIds ?: [0])
            ->groupBy('stock_article_id')
            ->selectRaw("stock_article_id, SUM(CASE WHEN type = 'ENTREE' THEN quantity ELSE -quantity END) AS stock, MAX(movement_date) AS last_date, COUNT(*) AS moves")
            ->get()->keyBy('stock_article_id');
        $used = DB::table('stock_movements')->where('project_id', $pid)->distinct()->pluck('stock_article_id')->flip();

        $unitNames = OrganizationalUnit::where('project_id', $pid)->pluck('name', 'id');
        $contracts = DB::table('contracts as c')->leftJoin('companies as co', 'co.id', '=', 'c.company_id')->where('c.project_id', $pid)
            ->orderBy('c.reference')->get(['c.id', 'c.reference', 'co.name as company']);
        $contractNumbers = $contracts->pluck('reference', 'id');
        $articles = $this->articlesQuery()->orderBy('number')->get()->map(function ($a) use ($stocks, $used, $unitNames, $contractNumbers) {
            $s = $stocks[$a->id] ?? null;
            $qty = $s ? round((float) $s->stock, 2) : 0.0;
            return self::articleView($a) + [
                'stock' => $qty, 'value' => round($qty * (float) $a->unit_price, 2), 'lastMovementDate' => $s ? Dates::part($s->last_date) : null,
                'level' => self::level($a, $qty, $s ? (int) $s->moves : 0), 'hasMovements' => isset($used[$a->id]),
                'ownerUnit' => $a->organizational_unit_id ? ($unitNames[$a->organizational_unit_id] ?? null) : null,
                'contractNumber' => $a->contract_id ? ($contractNumbers[$a->contract_id] ?? null) : null,
                'canEdit' => $this->canEditArticle($a),
            ];
        })->values();

        $active = $articles->where('isActive', true);
        return [
            'units' => $units->map(fn ($u) => self::unitView($u))->values(),
            'unitId' => $unit ? $unit->id : null,
            'canChooseUnit' => $this->hasNationalView(),
            'writableUnitIds' => $this->writableUnitIds($units, 'can_manage_stock'),
            'canManageCatalog' => $this->canManageCatalog(),
            // Où vont les articles ajoutés par le compte : catalogue commun (coordination) ou son antenne.
            'catalogOwner' => $this->canManageCatalog() ? ($this->ownerUnitId() ? ($unitNames[$this->ownerUnitId()] ?? null) : 'Catalogue commun du projet') : null,
            'contracts' => $this->canManageCatalog() ? $contracts->map(fn ($c) => ['id' => (int) $c->id, 'number' => $c->reference, 'company' => (string) $c->company])->values() : [],
            // Unité où l'utilisateur saisit, sans avoir à la choisir ; null si son compte n'en a pas (vue nationale non affectée).
            'ownUnitId' => in_array((int) Ctx::user()->organizational_unit_id, $this->writableUnitIds($units, 'can_manage_stock'), true) ? (int) Ctx::user()->organizational_unit_id : null,
            'companies' => DB::table('companies')->where('project_id', $pid)->orderBy('name')->get(['id', 'name'])->values(),
            'articles' => $articles,
            'totals' => [
                'value' => round($articles->sum('value'), 2),
                'articles' => $active->count(),
                'low' => $active->whereIn('level', ['LOW', 'OUT'])->count(),
            ],
        ];
    }

    /** Fiche de stock d'un article dans une unité (?unit_id), avec report du solde antérieur si une période est choisie. */
    public function card(Request $r, int $id)
    {
        $article = $this->articleOr404($id);
        $units = $this->units();
        $unit = $this->requestedUnit($r, $units, true);
        $from = Dates::valid($r->query('from')) ? Dates::part($r->query('from')) : null;
        $to = Dates::valid($r->query('to')) ? Dates::part($r->query('to')) : null;

        $moves = $this->ledger($article->id, $unit->id);
        $opening = 0.0;
        $lines = [];
        foreach (StockLedger::withBalances($moves) as $m) {
            if ($from && $m['date'] < $from) { $opening = $m['balance']; continue; }
            if ($to && $m['date'] > $to) continue;
            $lines[] = self::lineView($m);
        }
        $stock = StockLedger::balance($moves);

        return [
            'article' => self::articleView($article),
            'unit' => self::unitView($unit),
            'units' => $units->map(fn ($u) => self::unitView($u))->values(),
            'canChooseUnit' => $this->hasNationalView(),
            'canWrite' => in_array((int) $unit->id, $this->writableUnitIds($units, 'can_manage_stock'), true) && $article->is_active,
            // Fiche d'une autre unité, consultée par un gestionnaire de stock à vue nationale : lecture seule.
            'otherUnit' => Ctx::can('can_manage_stock') && (int) $unit->id !== (int) Ctx::user()->organizational_unit_id && !Ctx::user()->is_super_admin,

            'period' => ['from' => $from, 'to' => $to, 'opening' => $opening],
            'stock' => $stock,
            'value' => round($stock * (float) $article->unit_price, 2),
            'lines' => $lines,
            'companies' => DB::table('companies')->where('project_id', Ctx::projectId())->orderBy('name')->get(['id', 'name'])->values(),
            // Suggestions de saisie : origines et destinations déjà utilisées dans l'unité (Approvisionnement, services…)
            'origins' => DB::table('stock_movements')->where('project_id', Ctx::projectId())->where('organizational_unit_id', $unit->id)
                ->select('origin_destination')->groupBy('origin_destination')->orderByRaw('COUNT(*) DESC')->limit(50)->pluck('origin_destination'),
        ];
    }

    // ------------------------------------------------------------------ Mouvements

    /**
     * Unité où l'opération est enregistrée : celle de l'utilisateur, sans la lui redemander ; seule la vue nationale
     * (administrateur) peut en désigner une autre. Contrôle de l'habilitation et du droit d'écriture.
     */
    private function writeUnit(Request $r): OrganizationalUnit
    {
        Ctx::need('can_manage_stock');
        $id = $r->filled('organizational_unit_id') ? (int) $r->input('organizational_unit_id') : (int) Ctx::user()->organizational_unit_id;
        if (!$id) throw ValidationException::withMessages(['organizational_unit_id' => 'Votre compte n’est rattaché à aucune unité : demandez votre affectation à l’administrateur.']);
        $unit = OrganizationalUnit::where('id', $id)->where('project_id', Ctx::projectId())->first();
        if (!$unit) throw ValidationException::withMessages(['organizational_unit_id' => 'Unité invalide pour ce projet.']);
        Ctx::needUnit($id);
        abort_unless($unit->is_active, 422, 'Cette unité est désactivée : aucun mouvement ne peut y être enregistré.');
        return $unit;
    }

    private static function quantity($raw, string $field = 'quantity'): float
    {
        $q = Dates::decimal($raw);
        if ($q === null || $q <= 0) throw ValidationException::withMessages([$field => 'Saisissez une quantité supérieure à zéro.']);
        return round($q, 2);
    }

    /**
     * Enregistre une ligne de fiche (à appeler dans une transaction) : contrôle du stock disponible pour une sortie, même
     * antidatée, N° d'opération suivant de la fiche, audit. $field nomme le champ en erreur (formulaire à plusieurs lignes).
     */
    private function record(StockArticle $article, OrganizationalUnit $unit, array $d, string $field = 'quantity'): StockMovement
    {
        abort_unless($article->is_active, 422, "L’article « {$article->designation} » est désactivé : réactivez-le dans le catalogue avant de saisir un mouvement.");
        $moves = $this->ledger($article->id, $unit->id);
        if ($d['type'] === StockLedger::SORTIE) {
            $available = StockLedger::balance(array_filter($moves, fn ($m) => $m['date'] <= $d['movement_date']));
            if ($d['quantity'] > $available) {
                throw ValidationException::withMessages([$field => 'Stock insuffisant au ' . Dates::fr($d['movement_date']) . ' : ' . self::qty($available) . " {$article->unit} disponible(s)."]);
            }
            $moves[] = ['id' => PHP_INT_MAX, 'date' => $d['movement_date'], 'type' => StockLedger::SORTIE, 'quantity' => $d['quantity'], 'cancelled' => false];
            if ($short = StockLedger::firstShortage($moves)) {
                throw ValidationException::withMessages([$field => 'Cette sortie antidatée rendrait le stock négatif le ' . Dates::fr($short['date']) . ', après une sortie déjà enregistrée.']);
            }
        }
        $next = (int) StockMovement::where('organizational_unit_id', $unit->id)->where('stock_article_id', $article->id)->max('operation_no') + 1;
        $m = StockMovement::create([
            'project_id' => Ctx::projectId(), 'organizational_unit_id' => $unit->id, 'stock_article_id' => $article->id, 'operation_no' => $next,
            'movement_date' => $d['movement_date'], 'type' => $d['type'], 'quantity' => $d['quantity'],
            'origin_destination' => $d['origin_destination'], 'company_id' => $d['company_id'] ?? null, 'reference' => $d['reference'] ?? null,
            'observation' => $d['observation'] ?? null, 'created_by' => Ctx::user()->id,
        ]);
        $kind = $d['type'] === StockLedger::ENTREE ? 'Entrée' : 'Sortie';
        Ctx::auditChange('CREATION', 'StockMovement', $m->id,
            "{$kind} N° {$next} de " . self::qty($d['quantity']) . " {$article->unit} « {$article->designation} » ({$unit->name}), le " . Dates::fr($d['movement_date']) . " : {$m->origin_destination}.",
            null, collect($m->getAttributes())->except(['created_at', 'updated_at'])->all());
        return $m;
    }

    private static function checkDate(string $date, string $field): void
    {
        if ($date > Dates::today()) throw ValidationException::withMessages([$field => 'La date ne peut pas être postérieure à aujourd’hui.']);
    }

    /** Fournisseur : entreprise du projet (titulaire du contrat de fournitures). */
    private static function companyRule(): array
    {
        return ['nullable', 'integer', Rule::exists('companies', 'id')->where('project_id', Ctx::projectId())];
    }

    public function storeMovement(Request $r)
    {
        $unit = $this->writeUnit($r);
        $pid = Ctx::projectId();
        $d = $r->validate([
            'stock_article_id' => ['required', 'integer', Rule::exists('stock_articles', 'id')->where('project_id', $pid)],
            'movement_date' => 'required|date_format:Y-m-d',
            'type' => ['required', Rule::in([StockLedger::ENTREE, StockLedger::SORTIE])],
            'origin_destination' => 'nullable|string|max:190',
            'company_id' => self::companyRule(),
            'reference' => 'nullable|string|max:100',
            'observation' => 'nullable|string|max:500',
        ], ['company_id.exists' => 'Cette entreprise n’appartient pas au projet.'], ['movement_date' => 'date', 'origin_destination' => 'origine ou destination', 'stock_article_id' => 'article']);
        $d['quantity'] = self::quantity($r->input('quantity'));
        self::checkDate($d['movement_date'], 'movement_date');
        $company = $d['type'] === StockLedger::ENTREE && !empty($d['company_id']) ? DB::table('companies')->where('id', $d['company_id'])->value('name') : null;
        if ($d['type'] === StockLedger::SORTIE) { $d['company_id'] = null; $d['reference'] = null; }
        $d['origin_destination'] = trim((string) ($d['origin_destination'] ?? '')) ?: ($company ? "Livraison {$company}" : '');
        if ($d['origin_destination'] === '') throw ValidationException::withMessages(['origin_destination' => $d['type'] === StockLedger::SORTIE ? 'Indiquez la destination (service ou bénéficiaire).' : 'Indiquez l’origine ou le fournisseur.']);
        foreach (['reference', 'observation'] as $k) $d[$k] = isset($d[$k]) && trim($d[$k]) !== '' ? trim($d[$k]) : null;

        return DB::transaction(function () use ($d, $unit) {
            // Verrou sur l'article : deux sorties simultanées ne peuvent pas consommer le même stock.
            $article = StockArticle::where('id', $this->articleOr404((int) $d['stock_article_id'])->id)->lockForUpdate()->first();
            $m = $this->record($article, $unit, $d);
            return ['message' => ($d['type'] === StockLedger::ENTREE ? 'Entrée' : 'Sortie') . " N° {$m->operation_no} enregistrée.", 'id' => $m->id];
        });
    }

    /**
     * Réception d'une livraison de l'entreprise titulaire : plusieurs articles en une fois, une entrée par article sur sa
     * fiche (même fournisseur, même n° de bon de livraison). Tout ou rien.
     */
    public function storeDelivery(Request $r)
    {
        $unit = $this->writeUnit($r);
        $pid = Ctx::projectId();
        $d = $r->validate([
            'company_id' => ['required', ...array_slice(self::companyRule(), 1)],
            'movement_date' => 'required|date_format:Y-m-d',
            'reference' => 'nullable|string|max:100',
            'observation' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.stock_article_id' => ['required', 'integer', 'distinct', Rule::exists('stock_articles', 'id')->where('project_id', $pid)],
        ], [
            'company_id.required' => 'Choisissez l’entreprise qui a livré.',
            'company_id.exists' => 'Cette entreprise n’appartient pas au projet.',
            'lines.required' => 'Ajoutez au moins un article livré.',
            'lines.*.stock_article_id.distinct' => 'Cet article figure deux fois dans la livraison.',
            'lines.*.stock_article_id.required' => 'Choisissez l’article.',
        ], ['movement_date' => 'date de livraison', 'reference' => 'n° de bon de livraison']);
        self::checkDate($d['movement_date'], 'movement_date');
        $company = DB::table('companies')->where('id', $d['company_id'])->value('name');
        $lines = [];
        foreach ($r->input('lines') as $i => $l) $lines[] = ['article' => (int) $l['stock_article_id'], 'quantity' => self::quantity($l['quantity'] ?? null, "lines.$i.quantity")];

        return DB::transaction(function () use ($d, $lines, $unit, $company) {
            foreach ($lines as $l) {
                $article = StockArticle::where('id', $this->articleOr404((int) $l['article'])->id)->lockForUpdate()->first();
                $this->record($article, $unit, [
                    'type' => StockLedger::ENTREE, 'movement_date' => $d['movement_date'], 'quantity' => $l['quantity'],
                    'origin_destination' => "Livraison {$company}", 'company_id' => $d['company_id'],
                    'reference' => isset($d['reference']) && trim($d['reference']) !== '' ? trim($d['reference']) : null,
                    'observation' => isset($d['observation']) && trim($d['observation']) !== '' ? trim($d['observation']) : null,
                ]);
            }
            $n = count($lines);
            return ['message' => "Livraison de {$company} enregistrée : {$n} article(s) entré(s) en stock à {$unit->name}."];
        });
    }

    /** Annulation motivée : la ligne reste sur la fiche, barrée, et ne compte plus dans le solde. */
    public function cancelMovement(Request $r, int $id)
    {
        Ctx::need('can_manage_stock');
        $d = $r->validate(['reason' => 'required|string|min:5|max:500'], [], ['reason' => 'motif']);
        $m = StockMovement::where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($m, 404, 'Mouvement introuvable.');
        Ctx::needUnit((int) $m->organizational_unit_id);
        abort_if($m->cancelled_at !== null, 422, 'Ce mouvement est déjà annulé.');

        return DB::transaction(function () use ($m, $d) {
            $article = StockArticle::where('id', $m->stock_article_id)->lockForUpdate()->first();
            $moves = array_map(fn ($x) => $x['id'] === (int) $m->id ? ['cancelled' => true] + $x : $x, $this->ledger($article->id, (int) $m->organizational_unit_id));
            if ($short = StockLedger::firstShortage($moves)) {
                abort(422, 'Impossible d’annuler cette entrée : le stock deviendrait négatif le ' . Dates::fr($short['date']) . '. Annulez d’abord les sorties concernées.');
            }
            $before = collect($m->getAttributes())->except(['created_at', 'updated_at'])->all();
            $m->forceFill(['cancelled_at' => now(), 'cancelled_by' => Ctx::user()->id, 'cancel_reason' => trim($d['reason'])])->save();
            Ctx::auditChange('ANNULATION', 'StockMovement', $m->id,
                ($m->type === StockLedger::ENTREE ? 'Entrée' : 'Sortie') . ' de ' . self::qty((float) $m->quantity) . " {$article->unit} « {$article->designation} » du " . Dates::fr(Dates::part($m->movement_date)) . " annulée. Motif : {$m->cancel_reason}",
                $before, collect($m->fresh()->getAttributes())->except(['created_at', 'updated_at'])->all());
            return ['message' => 'Mouvement annulé. Il reste visible, barré, sur la fiche de stock.'];
        });
    }

    // ------------------------------------------------------------------ Catalogue des articles

    private function articleData(Request $r, ?int $owner, ?int $ignoreId = null): array
    {
        $pid = Ctx::projectId();
        $d = $r->validate([
            'number' => ['nullable', 'integer', 'min:1', 'max:99999', Rule::unique('stock_articles', 'number')->where('project_id', $pid)->ignore($ignoreId)],
            'designation' => ['required', 'string', 'max:255'],
            'unit' => 'required|string|max:40',
            'pack_unit' => 'nullable|string|max:40',
        ], [
            'number.unique' => 'Ce numéro de fiche est déjà attribué à un autre article.',
        ], ['number' => 'N° de fiche', 'designation' => 'désignation', 'unit' => 'unité']);
        if ($this->designationTaken($d['designation'], $owner, $ignoreId)) {
            throw ValidationException::withMessages(['designation' => $owner === null
                ? 'Un article porte déjà cette désignation dans le projet.' : 'Un article porte déjà cette désignation dans votre antenne ou dans le catalogue commun.']);
        }

        $price = Dates::decimal($r->input('unit_price'));
        if ($price === null || $price < 0) throw ValidationException::withMessages(['unit_price' => 'Saisissez un prix unitaire positif ou nul, en USD.']);
        $threshold = null;
        if (trim((string) $r->input('alert_threshold', '')) !== '') {
            $threshold = Dates::decimal($r->input('alert_threshold'));
            if ($threshold === null || $threshold < 0) throw ValidationException::withMessages(['alert_threshold' => 'Le seuil d’alerte doit être un nombre positif ou nul.']);
        }
        // Colisage facultatif : « 1 Carton = 12 pièces » ; le stock reste tenu dans l'unité de l'article.
        $pack = null;
        if (trim((string) $r->input('pack_size', '')) !== '') {
            $pack = Dates::decimal($r->input('pack_size'));
            if ($pack === null || $pack <= 0) throw ValidationException::withMessages(['pack_size' => 'Le contenu d’une unité doit être un nombre supérieur à zéro.']);
            if (trim((string) $r->input('pack_unit', '')) === '') throw ValidationException::withMessages(['pack_unit' => 'Précisez ce que contient une unité (ex. pièces).']);
        }
        return [
            'number' => $d['number'] ?? null, 'designation' => trim($d['designation']), 'unit' => trim($d['unit']),
            'pack_size' => $pack === null ? null : round($pack, 2), 'pack_unit' => $pack === null ? null : trim((string) $d['pack_unit']),
            'unit_price' => round($price, 2), 'alert_threshold' => $threshold === null ? null : round($threshold, 2),
        ];
    }

    // ------------------------------------------------------------------ Import du catalogue

    /** Modèle Excel à remplir, aux colonnes du document « Prix unitaires évalués ». */
    public function importTemplate()
    {
        $H = Xlsx::HEADER; $T = Xlsx::TEXT; $M = Xlsx::MONEY;
        $rows = [array_map(fn ($h) => ['v' => $h, 's' => $H], ['N°', 'ARTICLE', 'UNITÉ', 'QUANTITÉ', 'PU $', 'PT $'])];
        foreach ([[1, 'Agrafeuse (grand format) : qualité supérieure', 'Pqt', 1, 12], [2, 'Anneaux de 6 mm (paquet de 100 pièces)', 'Pqt/100pces', 1, 10]] as $x) {
            $rows[] = [['v' => $x[0], 's' => $T], ['v' => $x[1], 's' => $T], ['v' => $x[2], 's' => $T], ['v' => $x[3], 's' => $T], ['v' => $x[4], 's' => $M], ['v' => $x[3] * $x[4], 's' => $M]];
        }
        $bin = Xlsx::build([['name' => 'Prix unitaires évalués', 'rows' => $rows, 'widths' => [8, 50, 16, 12, 12, 12], 'freezeRows' => 1]]);
        return response($bin, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="modele_prix_unitaires_evalues.xlsx"',
        ]);
    }

    /**
     * Import du catalogue depuis un fichier de l'appareil (.xlsx ou .csv). Avec dry_run=1, renvoie seulement l'aperçu
     * (lignes nouvelles, mises à jour, en erreur). Sinon, tout ou rien : crée les articles nouveaux, met à jour l'unité et
     * le PU des articles existants (même désignation) et, si demandé, enregistre les quantités comme stock initial de
     * l'unité de l'utilisateur.
     */
    public function import(Request $r)
    {
        $this->needCatalog();
        $r->validate([
            'file' => 'required|file|max:2048|mimes:xlsx,csv,txt',
            'initial_stock' => 'nullable|boolean',
            'movement_date' => 'nullable|date_format:Y-m-d',
            'contract_id' => ['nullable', 'integer', Rule::exists('contracts', 'id')->where('project_id', Ctx::projectId())],
        ], ['file.mimes' => 'Choisissez un fichier Excel (.xlsx) ou CSV.', 'file.max' => 'Le fichier dépasse 2 Mo.', 'contract_id.exists' => 'Ce contrat n’appartient pas au projet.'], ['file' => 'fichier', 'contract_id' => 'contrat']);
        $owner = $this->ownerUnitId();
        $contractId = $r->filled('contract_id') ? (int) $r->input('contract_id') : null;
        $file = $r->file('file');
        try {
            $raw = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? Xlsx::read($file->getRealPath()) : StockImport::readCsv((string) file_get_contents($file->getRealPath()));
            $rows = StockImport::parse($raw);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        if (!$rows) throw ValidationException::withMessages(['file' => 'Aucun article trouvé sous la ligne d’en-tête.']);

        $pid = Ctx::projectId();
        // Articles déjà connus de l'unité (catalogue commun compris) : mis à jour si l'unité les tient, utilisés tels quels sinon.
        $existing = StockArticle::where('project_id', $pid)->when($owner !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('organizational_unit_id')->orWhere('organizational_unit_id', $owner)))
            ->get()->keyBy(fn ($a) => mb_strtolower($a->designation));
        $usedNumbers = StockArticle::where('project_id', $pid)->pluck('designation', 'number');
        $seen = [];
        foreach ($rows as &$row) {
            $key = mb_strtolower($row['designation']);
            if (isset($seen[$key])) $row['errors'][] = 'Article en double dans le fichier (ligne ' . $seen[$key] . ')';
            $seen[$key] = $row['line'];
            $row['status'] = $row['errors'] ? 'ERROR' : (isset($existing[$key]) ? ($this->canEditArticle($existing[$key]) ? 'UPDATE' : 'KEEP') : 'NEW');
            if ($row['number'] && isset($usedNumbers[$row['number']]) && mb_strtolower($usedNumbers[$row['number']]) !== $key) $row['number'] = null; // numéro pris : attribué automatiquement
        }
        unset($row);
        $counts = ['new' => 0, 'update' => 0, 'keep' => 0, 'error' => 0];
        foreach ($rows as $row) $counts[strtolower($row['status'])]++;
        $preview = array_map(fn ($x) => [
            'line' => $x['line'], 'number' => $x['number'], 'designation' => $x['designation'], 'unit' => $x['unit'], 'quantity' => $x['quantity'],
            'unitPrice' => $x['price'], 'total' => $x['quantity'] !== null && $x['price'] !== null ? round($x['quantity'] * $x['price'], 2) : null,
            'status' => $x['status'], 'errors' => $x['errors'],
        ], $rows);
        if ($r->boolean('dry_run')) return ['rows' => $preview, 'counts' => $counts];
        if ($counts['error']) throw ValidationException::withMessages(['file' => "{$counts['error']} ligne(s) en erreur : corrigez le fichier avant l’import."]);

        $initial = $r->boolean('initial_stock');
        $unit = $initial ? $this->writeUnit($r) : null;
        $date = $r->input('movement_date') ?: Dates::today();
        self::checkDate($date, 'movement_date');

        return DB::transaction(function () use ($rows, $existing, $pid, $initial, $unit, $date, $counts, $owner, $contractId) {
            $next = (int) StockArticle::where('project_id', $pid)->lockForUpdate()->max('number');
            $stocked = 0;
            foreach ($rows as $row) {
                $a = $existing[mb_strtolower($row['designation'])] ?? null;
                if ($a && $row['status'] === 'KEEP') {
                    // Article du catalogue commun : utilisé tel quel (son PU est tenu par la coordination).
                } elseif ($a) {
                    $before = self::snapshot($a);
                    $a->fill(['unit' => $row['unit'], 'unit_price' => $row['price']] + ($contractId ? ['contract_id' => $contractId] : []))->save();
                    if ($a->wasChanged()) Ctx::auditChange('MODIFICATION', 'StockArticle', $a->id, "Article « {$a->designation} » mis à jour par import.", $before, self::snapshot($a));
                } else {
                    $number = $row['number'] && !StockArticle::where('project_id', $pid)->where('number', $row['number'])->exists() ? $row['number'] : ++$next;
                    $next = max($next, $number);
                    $a = StockArticle::create(['project_id' => $pid, 'organizational_unit_id' => $owner, 'contract_id' => $contractId, 'number' => $number,
                        'designation' => $row['designation'], 'unit' => $row['unit'], 'unit_price' => $row['price'], 'is_active' => true]);
                    Ctx::auditChange('CREATION', 'StockArticle', $a->id, "Article « {$a->designation} » importé (fiche N° " . sprintf('%03d', $number) . ').', null, self::snapshot($a));
                }
                if ($initial && $row['quantity'] > 0) {
                    $this->record($a, $unit, ['type' => StockLedger::ENTREE, 'movement_date' => $date, 'quantity' => round($row['quantity'], 2), 'origin_destination' => 'Stock initial (import)']);
                    $stocked++;
                }
            }
            $msg = "Import terminé : {$counts['new']} article(s) créé(s), {$counts['update']} mis à jour" . ($counts['keep'] ? ", {$counts['keep']} déjà au catalogue commun" : '');
            return ['message' => $msg . ($initial ? ", {$stocked} quantité(s) enregistrée(s) en stock initial à {$unit->name}." : '.'), 'counts' => $counts];
        });
    }

    private static function snapshot(StockArticle $a): array
    {
        return collect($a->getAttributes())->except(['created_at', 'updated_at'])->all();
    }

    public function storeArticle(Request $r)
    {
        $this->needCatalog();
        $owner = $this->ownerUnitId();
        $data = $this->articleData($r, $owner);
        return DB::transaction(function () use ($data, $owner) {
            $pid = Ctx::projectId();
            if (!$data['number']) $data['number'] = (int) StockArticle::where('project_id', $pid)->lockForUpdate()->max('number') + 1;
            // Sans contrat : article ajouté un par un, à l'unité du compte (catalogue commun pour la coordination).
            $a = StockArticle::create($data + ['project_id' => $pid, 'organizational_unit_id' => $owner, 'is_active' => true]);
            Ctx::auditChange('CREATION', 'StockArticle', $a->id, "Article « {$a->designation} » ajouté au catalogue (fiche N° " . sprintf('%03d', $a->number) . ').', null, self::snapshot($a));
            return ['message' => 'Article ajouté au catalogue.', 'id' => $a->id];
        });
    }

    public function updateArticle(Request $r, int $id)
    {
        $a = $this->articleOr404($id);
        $this->needEdit($a);
        $data = $this->articleData($r, $a->organizational_unit_id, $a->id);
        if (!$data['number']) unset($data['number']);
        $before = self::snapshot($a);
        $a->fill($data)->save();
        Ctx::auditChange('MODIFICATION', 'StockArticle', $a->id, "Article « {$a->designation} » modifié.", $before, self::snapshot($a));
        return ['message' => 'Article enregistré.'];
    }

    public function toggleArticle(int $id)
    {
        $a = $this->articleOr404($id);
        $this->needEdit($a);
        $before = self::snapshot($a);
        $a->is_active = !$a->is_active;
        $a->save();
        Ctx::auditChange($a->is_active ? 'ACTIVATION' : 'DESACTIVATION', 'StockArticle', $a->id, "Article « {$a->designation} » " . ($a->is_active ? 'réactivé.' : 'désactivé.'), $before, self::snapshot($a));
        return ['message' => $a->is_active ? 'Article réactivé.' : 'Article désactivé : il reste visible dans l’historique mais n’accepte plus de mouvement.'];
    }

    public function destroyArticle(int $id)
    {
        $a = $this->articleOr404($id);
        $this->needEdit($a);
        abort_if(StockMovement::where
('stock_article_id', $a->id)->exists(), 422, 'Cet article a déjà des mouvements de stock : désactivez-le au lieu de le supprimer.');
        $before = self::snapshot($a);
        $a->delete();
        Ctx::auditChange('SUPPRESSION', 'StockArticle', $id, "Article « {$before['designation']} » supprimé du catalogue.", $before, null);
        return ['message' => 'Article supprimé.'];
    }
}
