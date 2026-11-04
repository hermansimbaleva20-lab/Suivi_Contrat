<?php
namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use App\Support\DecompteLedger;
use App\Support\GoodsProcedure;
use App\Support\Loader;
use App\Support\Metrics;
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
        $blank = ['goods_procedure' => null, 'goods_origin' => null, 'payment_schedule' => null, 'delay_penalty_rate' => null, 'delay_penalty_cap' => null];
        if ($category !== 'BIENS') return $blank;
        $procedure = trim((string) $r->input('goods_procedure', $c['goods_procedure'] ?? ''));
        if ($procedure === '' && $c) return $blank;
        if (!GoodsProcedure::label($procedure)) {
            $e['goods_procedure'] = 'Sélectionnez la procédure du marché : BAD, nationale ou fournitures de bureau.';
            return $blank;
        }
        if (!GoodsProcedure::governs($procedure)) return ['goods_procedure' => $procedure] + $blank;
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
        abort_unless($c, 404, 'Contrat introuvable.');
        return $c;
    }

    public function index(Request $r)
    {
        $all = Loader::contracts(Ctx::projectId(), Ctx::phaseId());
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
        $views = array_map(fn ($c) => Views::contract($c, true), $all);
        return [
            'data' => array_slice($views, $offset, $size),
            'meta' => ['page' => $page, 'pages' => $pages, 'total' => count($all), 'size' => $size],
            'statusCounts' => array_count_values(array_column($views, 'status')),

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
            'contract' => Views::contract($c, true),
            'guarantee' => $c['guarantee'] ? Views::guarantee($c['guarantee']) : null,
            'amendments' => array_reverse($c['amendments']),
            'renewals' => array_reverse($c['renewals']),
            'goodsRules' => GoodsProcedure::describe($c['goods_procedure'], $c['goods_origin']),
            'documents' => $docs,
            'payments' => array_map(fn ($p) => ['id' => $p['id'], 'date' => $p['date'], 'amount' => $p['amount'], 'reference' => $p['reference'], 'observation' => $p['observation']], array_reverse($c['payments'])),
            'entries' => array_map(fn ($e) => ['id' => $e['id'], 'date' => $e['date'], 'site' => $e['site_id'] ? ($sitesById[$e['site_id']] ?? '—') : 'Contrat', 'progress' => $e['progress'], 'realization' => $e['realization'], 'observation' => $e['observation']], array_slice($entries, 0, 30)),
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
            $out[] = [
                'id' => (int) ($s['id'] ?? 0), 'name' => $name, 'province' => trim((string) ($s['province'] ?? '')), 'work_type' => trim((string) ($s['work_type'] ?? '')),
                'description' => trim((string) ($s['description'] ?? '')), 'quantity' => max(0, (float) (Dates::decimal($s['quantity'] ?? 1) ?? 1)) ?: 1,
            ];
        }
        return $out;
    }

    public function store(Request $r)
    {
        Ctx::need('can_manage_contracts');
        $pid = Ctx::projectId();
        $e = [];
        $number = trim((string) $r->input('reference', ''));
        if ($number === '') $e['reference'] = 'Le numéro du contrat est obligatoire.';
        elseif (DB::table('contracts')->where('project_id', $pid)->where('reference', $number)->exists()) $e['reference'] = 'Ce numéro de contrat existe déjà.';
        $companyId = (int) $r->input('company_id');
        if (!DB::table('companies')->where('id', $companyId)->where('project_id', $pid)->exists()) $e['company_id'] = 'Sélectionnez une entreprise du projet.';
        $category = trim((string) $r->input('category', ''));
        if (!in_array($category, ['TRAVAUX', 'BIENS', 'SERVICE'], true)) $e['category'] = 'Sélectionnez une catégorie.';
        [$isWorks, $isMulti] = $this->categoryFlags($category);
        $start = Dates::valid($r->input('start_date')) ? Dates::part($r->input('start_date')) : null;
        if (!$start) $e['start_date'] = 'La date de démarrage est obligatoire.';
        $signature = Dates::valid($r->input('signature_date')) ? Dates::part($r->input('signature_date')) : ($start ?: Dates::today());
        $duration = max(1, (int) $r->input('duration_months', 12));
        $amount = Dates::decimal($r->input('amount'));
        if ($amount === null || $amount < 0) $e['amount'] = 'Saisissez un montant valide, par exemple 1500000,50.';
        // Un contrat de SERVICE n'a ni avance de démarrage ni garantie de bonne exécution.
        $isService = $category === 'SERVICE';
        $initialRate = $isService ? 0.0 : (Dates::decimal($r->input('initial_financing_rate', 0)) ?? 0);
        if ($initialRate < 0 || $initialRate > 100) $e['initial_financing_rate'] = 'Le taux de l’avance de démarrage doit être compris entre 0 et 100 %.';
        $gRate = Dates::decimal($r->input('guarantee_rate', 5)) ?? 5;
        if (!$isService && ($gRate <= 0 || $gRate > 100)) $e['guarantee_rate'] = 'Le taux de garantie doit être supérieur à 0 et inférieur ou égal à 100 %.';
        $guaranteeBank = trim((string) $r->input('guarantee_bank_name', ''));
        if (!$isService && $guaranteeBank === '') $e['guarantee_bank_name'] = 'La banque émettrice de la garantie est obligatoire.';
        $end = $start ? Dates::addMonths($start, $duration) : null;
        // La garantie est liée au contrat : sa période est celle du contrat (démarrage → démarrage + durée).
        $gStart = $start;
        $gEnd = $end;

        $provinces = array_values(array_unique(array_filter(array_map('trim', (array) $r->input('selected_provinces', [])))));
        $mainProvince = trim((string) $r->input('province', ''));
        if ($isMulti) {
            if (!$provinces) $e['province'] = 'Sélectionnez au moins une province d’exécution pour un contrat BIEN ou SERVICE.';
            else $mainProvince = $provinces[0];
        } elseif ($mainProvince === '') $e['province'] = 'La province est obligatoire.';

        $sites = $this->cleanSites($r->input('sites', []));
        if (!$sites) $e['sites'] = 'Ajoutez au moins un site d’exécution.';
        $workNames = [];
        if ($isWorks) {
            $workNames = $r->input('work_mode') === 'Lourd' ? [self::AEP] : array_map('trim', (array) $r->input('selected_work_types', []));
            $workNames = array_values(array_unique(array_filter($workNames)));
        } else {
            $workNames = array_values(array_unique(array_filter(array_column($sites, 'work_type'))));
        }
        $goods = $this->goodsFields($r, $category, (float) $initialRate, $e);
        if ($isMulti && $provinces && $sites) {
            $entered = array_unique(array_filter(array_column($sites, 'province')));
            if (array_diff($provinces, $entered)) $e['sites'] = 'Ajoutez au moins un site pour chaque province sélectionnée.';
        }
        if ($e) $this->invalid($e);

        $id = DB::transaction(function () use ($goods, $r, $pid, $number, $companyId, $category, $isWorks, $isMulti, $isService, $start, $end, $signature, $duration, $amount, $initialRate, $gRate, $gStart, $gEnd, $mainProvince, $sites, $workNames, $guaranteeBank) {
            $cid = DB::table('contracts')->insertGetId([
                'project_id' => $pid, 'phase_id' => Ctx::phaseId(), 'company_id' => $companyId, 'reference' => $number, 'title' => trim((string) $r->input('title', '')) ?: null,
                'province' => $mainProvince, 'category' => $category, 'amount' => $amount, 'start_date' => $start, 'end_date' => $end, 'signature_date' => $signature,
                'duration_months' => $duration, 'initial_financing_rate' => $initialRate, 'activities' => trim((string) $r->input('activities', '')),
                'work_type_text' => implode('; ', $workNames), 'completed' => false, 'created_at' => now(), 'updated_at' => now(),
            ] + $goods);
            $rate = $gRate;
            if (!$isService) DB::table('performance_guarantees')->insert([
                'project_id' => $pid, 'contract_id' => $cid, 'company_id' => $companyId,
                'bank_name' => $guaranteeBank,
                'account_number' => trim((string) $r->input('guarantee_account_number', '')) ?: null,
                'rate' => $rate, 'amount' => round($amount * $rate / 100, 2), 'start_date' => $gStart, 'end_date' => $gEnd,
                'observation' => trim((string) $r->input('guarantee_observation', '')) ?: null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($sites as $s) {
                $wt = $s['work_type'] !== '' ? $s['work_type'] : ($workNames[0] ?? '');
                if ($wt !== '') $this->workType($category, $wt, $wt !== self::AEP);
                DB::table('contract_sites')->insert([
                    'contract_id' => $cid, 'province' => $isMulti ? ($s['province'] ?: $mainProvince) : $mainProvince, 'work_type' => $wt, 'name' => $s['name'],
                    'description' => $s['description'] !== '' ? $s['description'] : $wt, 'quantity' => $s['quantity'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            return $cid;
        });
        Ctx::audit('CREATION', 'Contract', $id, "Contrat {$number} créé avec " . count($sites) . ' site(s).');
        return response()->json(['id' => $id, 'message' => 'Contrat créé.'], 201);
    }

    public function update(Request $r, int $id)
    {
        Ctx::need('can_manage_contracts');
        $pid = Ctx::projectId();
        $c = $this->contractOr404($id);
        abort_if($c['terminated'], 422, 'Un contrat résilié ne peut plus être modifié.');
        $e = [];
        $number = trim((string) $r->input('reference', ''));
        if ($number === '') $e['reference'] = 'Le numéro du contrat est obligatoire.';
        elseif (DB::table('contracts')->where('project_id', $pid)->where('reference', $number)->where('id', '!=', $id)->exists()) $e['reference'] = 'Ce numéro de contrat existe déjà.';
        $companyId = (int) $r->input('company_id');
        if (!DB::table('companies')->where('id', $companyId)->where('project_id', $pid)->exists()) $e['company_id'] = 'Sélectionnez une entreprise du projet.';
        $category = trim((string) $r->input('category', $c['category']));
        if (!in_array($category, ['TRAVAUX', 'BIENS', 'SERVICE'], true)) $e['category'] = 'Sélectionnez une catégorie.';
        [$isWorks, $isMulti] = $this->categoryFlags($category);
        $start = Dates::valid($r->input('start_date')) ? Dates::part($r->input('start_date')) : null;
        if (!$start) $e['start_date'] = 'La date de démarrage est obligatoire.';
        $duration = max(1, (int) $r->input('duration_months', $c['duration_months'] ?: 12));
        $amount = Dates::decimal($r->input('amount'));
        if ($amount === null || $amount < 0) $e['amount'] = 'Saisissez un montant valide, par exemple 1500000,50.';
        elseif ($amount < (float) $c['renewed_amount']) $e['amount'] = 'Le montant ne peut pas être inférieur aux montants ajoutés par les renouvellements (' . number_format((float) $c['renewed_amount'], 2, ',', ' ') . ' USD).';
        $initialRate = $category === 'SERVICE' ? 0.0 : (Dates::decimal($r->input('initial_financing_rate', 0)) ?? 0);
        if ($initialRate < 0 || $initialRate > 100) $e['initial_financing_rate'] = 'Le taux de l’avance de démarrage doit être compris entre 0 et 100 %.';
        $mainProvince = trim((string) $r->input('province', $c['province']));
        $goods = $this->goodsFields($r, $category, (float) $initialRate, $e, $c);
        // Une fois une avance demandée ou un décompte saisi, les règles du marché ne changent plus : les lignes en dépendent.
        $goodsInUse = DB::table('contract_decomptes')->where('contract_id', $id)->where('status', '!=', DecompteLedger::REJECTED)->exists() || DB::table('contract_advances')->where('contract_id', $id)->where('status', '!=', 'REJETEE')->exists();
        $goodsTouched = GoodsProcedure::governs($c['goods_procedure']) || GoodsProcedure::governs($goods['goods_procedure']);
        if ($goodsInUse && $goodsTouched && ($goods['goods_procedure'] !== $c['goods_procedure'] || $goods['goods_origin'] !== $c['goods_origin'] || abs((float) $initialRate - (float) $c['initial_rate']) > 0.001
            || abs((float) $goods['delay_penalty_rate'] - (float) $c['delay_penalty_rate']) > 0.0001 || abs((float) $goods['delay_penalty_cap'] - (float) $c['delay_penalty_cap']) > 0.001)) {
            $e['goods_procedure'] = 'Une demande d’avance ou un décompte existe déjà : la procédure, la provenance, l’avance et la pénalité ne peuvent plus changer.';
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
        if ($e) $this->invalid($e);

        DB::transaction(function () use ($goods, $newGuaranteeRate, $r, $pid, $id, $number, $companyId, $category, $isWorks, $isMulti, $start, $duration, $amount, $initialRate, $mainProvince, $sites, $c) {
            // Après un avenant ou un renouvellement, la fin ne se recalcule que si le démarrage ou la durée changent vraiment.
            $end = ($c['amendments'] || $c['renewals']) && $start === $c['start_date'] && $duration === (int) $c['duration_months'] ? $c['end_date'] : Dates::addMonths($start, $duration);
            $signature = Dates::valid($r->input('signature_date')) ? Dates::part($r->input('signature_date')) : ($c['signature_date'] ?: $start);
            foreach ($sites as $s) {
                $wt = $s['work_type'];
                if ($wt !== '') $this->workType($category, $wt, $wt !== self::AEP);
                $data = ['province' => $isMulti ? $s['province'] : $mainProvince, 'work_type' => $wt, 'name' => $s['name'], 'description' => $s['description'], 'quantity' => $s['quantity'], 'updated_at' => now()];
                if ($s['id'] > 0) DB::table('contract_sites')->where('id', $s['id'])->where('contract_id', $id)->update($data);
                else DB::table('contract_sites')->insert($data + ['contract_id' => $id, 'created_at' => now()]);
            }
            $types = array_values(array_unique(array_filter(DB::table('contract_sites')->where('contract_id', $id)->pluck('work_type')->all())));
            if ($isWorks && $r->has('selected_work_types')) $types = array_values(array_unique(array_filter(array_map('trim', (array) $r->input('selected_work_types')))));
            DB::table('contracts')->where('id', $id)->update([
                'reference' => $number, 'company_id' => $companyId, 'category' => $category, 'province' => $mainProvince, 'signature_date' => $signature,
                'start_date' => $start, 'duration_months' => $duration, 'end_date' => $end, 'amount' => $amount, 'initial_financing_rate' => $initialRate,
                'activities' => trim((string) $r->input('activities', '')), 'title' => trim((string) $r->input('title', '')) ?: null, 'work_type_text' => implode('; ', $types), 'updated_at' => now(),
            ] + $goods);
            // La garantie reste liée au contrat ; synchroniser son entreprise et son montant si le contrat change, et sa
            // période (celle du contrat) tant qu'elle n'a été ni prolongée ni renouvelée.
            $guarantees = DB::table('performance_guarantees')->where('project_id', $pid)->where('contract_id', $id)->get(['id', 'rate']);
            foreach ($guarantees as $guarantee) {
                $rate = $newGuaranteeRate ?? (float) $guarantee->rate;
                $sync = ['company_id' => $companyId, 'rate' => $rate, 'amount' => round(((float) $amount - (float) $c['renewed_amount']) * $rate / 100, 2), 'updated_at' => now()];
                // La garantie ne suit pas un contrat renouvelé : elle n'est plus exigée pour la nouvelle période.
                if (!$c['renewals'] && !DB::table('guarantee_extensions')->where('performance_guarantee_id', $guarantee->id)->exists()) $sync += ['start_date' => $start, 'end_date' => $end];
                DB::table('performance_guarantees')->where('id', $guarantee->id)->update($sync);
            }
        });
        Ctx::audit('MODIFICATION', 'Contract', $id, "Contrat {$number} modifié avec " . count($sites) . ' site(s) enregistré(s).');
        return ['message' => "Contrat {$number} modifié. Les informations des sites ont également été enregistrées."];
    }

    public function destroy(Request $r, int $id)
    {
        Ctx::need('can_delete_contracts');
        $reason = trim((string) $r->input('reason', ''));
        abort_if(mb_strlen($reason) < 5, 422, 'Une raison valable (minimum 5 caractères) est obligatoire avant suppression.');
        $c = $this->contractOr404($id);
        foreach (DB::table('contract_documents')->where('contract_id', $id)->get() as $d) Storage::disk('local')->delete($d->storage_path);
        DB::table('contract_documents')->where('contract_id', $id)->delete();
        DB::table('contracts')->where('id', $id)->delete();
        Ctx::audit('SUPPRESSION', 'Contract', $id, "Contrat {$c['number']} supprimé. Motif : {$reason}");
        return ['message' => 'Contrat supprimé.'];
    }

    public function terminate(Request $r, int $id)
    {
        Ctx::need('can_manage_contracts');
        $c = $this->contractOr404($id);
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
        Ctx::need('can_manage_contracts');
        $c = $this->contractOr404($id);
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
        $added = Dates::decimal($r->input('added_amount'));
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
        Ctx::need('can_manage_contracts');
        $c = $this->contractOr404($id);
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
