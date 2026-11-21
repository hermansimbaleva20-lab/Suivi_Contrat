<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * État d'exécution des travaux, activités et prestations (décisions du 10 et du 16/11/2026), sur le modèle de la matrice du
 * projet : par composante (« A. Développement des infrastructures ; Coût : … UC »), puis par catégorie de la composante
 * (« Catégorie Travaux », avec son coût), puis par province, chaque contrat (activité, fournisseur, coût, délai consommé,
 * réalisation physique, taux de réalisation, engagement, paiement, taux de paiement, observations) et chacun de ses sites.
 * Les contrats d'une même activité de la composante sont regroupés : la cellule « Composantes » porte l'activité et son budget.
 * Un contrat sans activité reste sous sa composante éventuelle, rangé par la catégorie du contrat (Travaux, Biens, Services).
 * Fonctions pures sur les contrats de Loader, arrêtées à une date ($asOf).
 */
class ExecutionReport
{
    public const CATEGORIES = ['TRAVAUX' => 'Catégorie Travaux', 'BIENS' => 'Catégorie Biens', 'SERVICE' => 'Catégorie Services'];

    /** Dernière situation (date puis ordre de saisie) au plus tard à $asOf parmi les lignes retenues par $keep. */
    private static function latest(array $entries, string $asOf, callable $keep): ?array
    {
        $best = null;
        foreach ($entries as $e) {
            if ($e['date'] > $asOf || !$keep($e)) continue;
            if ($best === null || $e['date'] > $best['date'] || ($e['date'] === $best['date'] && $e['id'] > $best['id'])) $best = $e;
        }
        return $best;
    }

    private static function pct(float $v): string
    {
        return number_format($v, 0, ',', ' ') . ' %';
    }

    /** Observation rédigée à partir des chiffres, comme dans la matrice : délai consommé contre avancement, puis le constat saisi. */
    public static function observation(array $c, float $progress, float $elapsed, string $asOf, string $noted): string
    {
        // Somme pondérée : 99,999 99 % vaut 100 %, comme à l'affichage.
        $progress = round($progress, 2);
        $elapsed = round($elapsed, 2);
        if ($c['terminated'] && $c['termination_date'] && $c['termination_date'] <= $asOf) {
            $text = 'Contrat résilié le ' . Dates::fr($c['termination_date']) . '.' . ($c['termination_reason'] ? " {$c['termination_reason']}" : '');
        } elseif ($progress >= 100) {
            $text = 'Exécution achevée à 100 %.';
        } elseif ($elapsed - $progress >= 0.5) {
            $text = 'À ce jour, le taux d’exécution devrait être de ' . self::pct($elapsed) . ', soit un retard de ' . self::pct($elapsed - $progress) . '.';
        } else {
            $text = 'Avancement conforme au délai consommé (' . self::pct($elapsed) . ').';
        }
        return trim($text . ($noted !== '' ? " {$noted}" : ''));
    }

    /** Ligne d'un contrat et de ses sites. */
    public static function contract(array $c, string $asOf): array
    {
        $progress = Metrics::progress($c, $asOf);
        $elapsed = Metrics::elapsed($c, $asOf);
        $paid = Metrics::initialFinancing($c, $asOf) + Metrics::totalPaid($c, $asOf);
        $w = Metrics::weighting($c);
        // Ligne du contrat : ses propres situations (sans site, installation comprise) ; celles des sites vont sous chaque site.
        $latest = self::latest($c['entries'], $asOf, fn ($e) => empty($e['site_id']) && ($e['realization'] !== '' || $e['observation'] !== ''));
        $sites = [];
        foreach ($c['sites'] as $s) {
            $last = self::latest($s['entries'], $asOf, fn ($e) => $e['realization'] !== '' || $e['observation'] !== '');
            $sites[] = [
                'id' => $s['id'], 'name' => $s['name'], 'province' => $s['province'], 'workType' => $s['work_type'], 'description' => $s['description'],
                'amount' => $s['amount'] ?? null, 'progress' => Metrics::pct(Metrics::siteProgress($s, $asOf)),
                'situation' => $last ? $last['realization'] : '', 'observation' => $last ? $last['observation'] : '', 'situationDate' => $last ? $last['date'] : null,
            ];
        }
        $renewal = null;
        foreach ($c['renewals'] ?? [] as $r) if ($r['period_start'] <= $asOf) $renewal = $r;
        // Performance environnementale et sociale : la dernière renseignée à la date d'arrêt, toutes situations confondues.
        $es = self::latest($c['entries'], $asOf, fn ($e) => ($e['es_performance'] ?? '') !== '');
        return [
            'component' => (string) ($c['component'] ?? ''), 'componentBudget' => $c['component_budget'] ?? null, 'esPerformance' => $es ? $es['es_performance'] : '',
            // Activité de la composante : cellule « Composantes » et « Budget » de la matrice, fusionnée sur ses contrats.
            'group' => (string) ($c['activity_name'] ?? ''), 'groupBudget' => $c['activity_budget'] ?? null, 'groupId' => $c['activity_id'] ?? null,
            'id' => $c['id'], 'number' => $c['number'], 'activity' => $c['activity'], 'title' => $c['title'], 'company' => $c['company'], 'category' => $c['category'],
            'province' => $c['province'], 'provinces' => array_values(array_unique(array_filter(array_merge([$c['province']], array_column($c['sites'], 'province'))))),
            'signatureDate' => $c['signature_date'], 'startDate' => $renewal ? $renewal['period_start'] : $c['start_date'], 'endDate' => $c['end_date'],
            'durationMonths' => $renewal ? $renewal['months'] : $c['duration_months'], 'amendments' => count(array_filter($c['amendments'], fn ($a) => $a['date'] <= $asOf)),
            'amount' => round((float) $c['amount'], 2), 'elapsed' => Metrics::pct($elapsed), 'progress' => Metrics::pct($progress), 'status' => Metrics::status($c, $asOf),
            // L'installation de chantier concerne tout un contrat de TRAVAUX (coût éventuellement non renseigné) ; null sinon.
            'installation' => Metrics::hasInstallation($c) ? ['cost' => isset($c['installation_cost']) ? round((float) $c['installation_cost'], 2) : null, 'progress' => Metrics::pct(Metrics::installationProgress($c, $asOf))] : null,
            'weighted' => $w !== null,
            'realization' => $latest ? $latest['realization'] : '', 'realizationDate' => $latest ? $latest['date'] : null,
            'engagement' => round((float) $c['amount'], 2), 'paid' => round($paid, 2), 'paymentRate' => Metrics::pct(Metrics::paymentPercent($c, $asOf)),
            'observation' => self::observation($c, $progress, $elapsed, $asOf, $latest ? $latest['observation'] : ''),
            'sites' => $sites,
        ];
    }

    /** Ouvrages terminés (sites à 100 %) par type d'ouvrage, comme « EP : 15 ; CS : 14 » dans la matrice. */
    public static function completed(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) foreach ($r['sites'] as $s) {
            if ($s['progress'] < 100) continue;
            $type = trim((string) $s['workType']) ?: 'Autres ouvrages';
            $out[$type] = ($out[$type] ?? 0) + 1;
        }
        uksort($out, [self::class, 'compare']);
        return array_map(fn ($k, $v) => ['type' => $k, 'count' => $v], array_keys($out), $out);
    }

    /** Ordre alphabétique sans tenir compte des accents ni de la casse : « École » avant « Système », « Équateur » avant « Haut-Katanga ». */
    public static function compare(string $a, string $b): int
    {
        return strnatcasecmp(Str::ascii($a), Str::ascii($b)) ?: strcmp($a, $b);
    }

    /**
     * Groupes de contrats consécutifs d'une même activité : intitulé, budget (le premier renseigné), contrats et nombre de
     * lignes de la matrice (un contrat = 1 ligne + ses sites), pour fusionner les cellules « Composantes » et « Budget ».
     */
    public static function components(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['name'] === $r['group']) {
                $out[$last]['contractIds'][] = $r['id'];
                $out[$last]['lines'] += 1 + count($r['sites']);
                if ($out[$last]['budget'] === null) $out[$last]['budget'] = $r['groupBudget'];
                continue;
            }
            $out[] = ['name' => $r['group'], 'budget' => $r['groupBudget'], 'contractIds' => [$r['id']], 'lines' => 1 + count($r['sites'])];
        }
        return $out;
    }

    /** « Catégorie Travaux » pour une catégorie saisie « Travaux » ; l'intitulé est gardé s'il commence déjà par « Catégorie ». */
    public static function categoryLabel(string $name): string
    {
        return preg_match('/^cat[ée]gorie\b/iu', $name) ? $name : "Catégorie {$name}";
    }

    /** Coût total et taux de réalisation physique pondéré par le coût des contrats. */
    private static function totals(array $rows): array
    {
        $amount = array_sum(array_column($rows, 'amount'));
        $weighted = 0.0;
        foreach ($rows as $r) $weighted += $r['amount'] * $r['progress'];
        $paid = array_sum(array_column($rows, 'paid'));
        return [
            'contracts' => count($rows), 'amount' => round($amount, 2), 'paid' => round($paid, 2),
            'progress' => $amount > 0 ? Metrics::pct($weighted / $amount) : Metrics::pct(count($rows) ? array_sum(array_column($rows, 'progress')) / count($rows) : 0),
            'paymentRate' => $amount > 0 ? Metrics::pct($paid / $amount * 100) : 0.0,
        ];
    }

    /** Provinces d'une catégorie, par ordre alphabétique ; contrats regroupés par activité (sans activité en dernier), puis par numéro. */
    private static function provinces(array $byProvince): array
    {
        uksort($byProvince, [self::class, 'compare']);
        $out = [];
        foreach ($byProvince as $prov => $rows) {
            // Activités dans l'ordre de leur saisie (sans activité en dernier), puis contrats par numéro.
            usort($rows, fn ($a, $b) => (($a['groupId'] === null) <=> ($b['groupId'] === null)) ?: ($a['groupId'] <=> $b['groupId']) ?: strnatcasecmp($a['number'], $b['number']));
            $out[] = ['province' => (string) $prov, 'completed' => self::completed($rows), 'totals' => self::totals($rows), 'rows' => $rows, 'components' => self::components($rows)];
        }
        return $out;
    }

    /**
     * Matrice complète : composantes (ordre naturel des intitulés, « A. » avant « B. » ; contrats sans composante en dernier),
     * puis catégories de la composante (celles des activités, puis Travaux, Biens, Services pour les contrats sans activité),
     * puis provinces par ordre alphabétique, contrats par activité et par numéro. Un contrat est classé sous sa province
     * principale ; ses sites d'autres provinces restent sous lui.
     */
    public static function build(array $contracts, string $asOf): array
    {
        $sections = [];
        foreach ($contracts as $c) {
            $row = self::contract($c, $asOf);
            $sid = (int) ($c['component_id'] ?? 0);
            $sections[$sid] ??= ['id' => $sid ?: null, 'name' => $sid ? (string) $c['component'] : '', 'total' => $sid ? ($c['component_budget'] ?? null) : null, 'cats' => []];
            if (!empty($c['component_category_id'])) {
                $key = 'k' . $c['component_category_id'];
                $sections[$sid]['cats'][$key] ??= ['category' => $key, 'label' => self::categoryLabel((string) $c['component_category']), 'name' => (string) $c['component_category'], 'cost' => $c['component_category_cost'] ?? null, 'order' => 0, 'sortId' => (int) $c['component_category_id'], 'byProvince' => []];
            } else {
                $fixed = isset(self::CATEGORIES[$row['category']]) ? $row['category'] : 'TRAVAUX';
                $key = 'f' . $fixed;
                $sections[$sid]['cats'][$key] ??= ['category' => $fixed, 'label' => self::CATEGORIES[$fixed], 'name' => self::CATEGORIES[$fixed], 'cost' => null, 'order' => 1 + array_search($fixed, array_keys(self::CATEGORIES), true), 'sortId' => 0, 'byProvince' => []];
            }
            $prov = trim((string) $row['province']) ?: 'Province non renseignée';
            $sections[$sid]['cats'][$key]['byProvince'][$prov][] = $row;
        }
        uasort($sections, fn ($a, $b) => (($a['id'] === null) <=> ($b['id'] === null)) ?: self::compare($a['name'], $b['name']));

        $out = [];
        $all = [];
        foreach ($sections as $s) {
            $cats = array_values($s['cats']);
            // Catégories des activités dans l'ordre de leur saisie, puis Travaux, Biens, Services (contrats sans activité).
            usort($cats, fn ($a, $b) => ($a['order'] <=> $b['order']) ?: ($a['sortId'] <=> $b['sortId']));
            $categories = [];
            $sectionRows = [];
            foreach ($cats as $k) {
                $provinces = self::provinces($k['byProvince']);
                $catRows = array_merge(...array_column($provinces, 'rows'));
                $categories[] = ['category' => $k['category'], 'label' => $k['label'], 'cost' => $k['cost'], 'totals' => self::totals($catRows), 'provinces' => $provinces];
                $sectionRows = array_merge($sectionRows, $catRows);
            }
            $out[] = ['id' => $s['id'], 'name' => $s['name'], 'total' => $s['total'], 'totals' => self::totals($sectionRows), 'categories' => $categories];
            $all = array_merge($all, $sectionRows);
        }
        return ['asOf' => $asOf, 'sections' => $out, 'totals' => self::totals($all), 'completed' => self::completed($all)];
    }
}
