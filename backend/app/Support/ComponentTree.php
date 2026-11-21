<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Composantes du projet et leur détail (décision du 16/11/2026) : composante → catégories (intitulé seul) → activités,
 * chacune avec son coût en UC (colonne budget). Le coût d'une catégorie est la somme des coûts de
 * ses activités, et le montant total d'une composante la somme des coûts de ses catégories ; sans activité chiffrée, le
 * budget saisi sur la composante avant cette décision (project_components.budget) est repris.
 * Tant que la base n'a pas reçu la mise à jour du 16/11/2026 (tables absentes), tout se comporte comme avant : composantes
 * sans détail, contrats sans activité.
 */
class ComponentTree
{
    private static ?bool $ready = null;

    /** Tables et colonnes de la mise à jour du 16/11/2026 présentes. */
    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('component_categories') && Schema::hasTable('component_activities') && Schema::hasColumn('contracts', 'activity_id');
    }

    private static function money($v): ?float
    {
        return $v === null ? null : round((float) $v, 2);
    }

    /**
     * Composantes du projet dans l'ordre naturel des intitulés (« A. … » avant « B. … »), avec leurs catégories et leurs
     * activités dans l'ordre de saisie.
     * $contracts : nombre de contrats par activité et par composante, pour savoir ce qui peut être supprimé.
     */
    public static function load(int $projectId): array
    {
        $components = DB::table('project_components')->where('project_id', $projectId)->get();
        $categories = collect();
        $activities = collect();
        $byActivity = collect();
        if (self::ready() && $components->isNotEmpty()) {
            $categories = DB::table('component_categories')->whereIn('component_id', $components->pluck('id'))->get();
            if ($categories->isNotEmpty()) $activities = DB::table('component_activities')->whereIn('category_id', $categories->pluck('id'))->get();
            $byActivity = DB::table('contracts')->where('project_id', $projectId)->whereNotNull('activity_id')->groupBy('activity_id')->selectRaw('activity_id, COUNT(*) AS n')->pluck('n', 'activity_id');
        }
        $byComponent = DB::table('contracts')->where('project_id', $projectId)->whereNotNull('component_id')->groupBy('component_id')->selectRaw('component_id, COUNT(*) AS n')->pluck('n', 'component_id');
        $sort = fn (array $a, array $b) => ExecutionReport::compare($a['name'], $b['name']);
        $byId = fn (array $a, array $b) => $a['id'] <=> $b['id'];

        $actsBy = [];
        foreach ($activities as $a) {
            $actsBy[$a->category_id][] = [
                'id' => (int) $a->id, 'categoryId' => (int) $a->category_id, 'name' => (string) $a->name, 'budget' => self::money($a->budget),
                'isActive' => (bool) $a->is_active, 'contracts' => (int) ($byActivity[$a->id] ?? 0),
            ];
        }
        $catsBy = [];
        foreach ($categories as $c) {
            // Catégories et activités dans l'ordre de leur saisie, comme dans la matrice du projet (« Travaux » d'abord, etc.).
            $acts = $actsBy[$c->id] ?? [];
            usort($acts, $byId);
            // Coût de la catégorie : somme des coûts de ses activités, désactivées comprises (décision du 16/11/2026 : le coût
            // ne se saisit plus sur la catégorie ; la colonne component_categories.cost n'est plus lue).
            $budgets = array_filter(array_column($acts, 'budget'), fn ($v) => $v !== null);
            $cost = $budgets ? round(array_sum($budgets), 2) : null;
            $catsBy[$c->component_id][] = [
                'id' => (int) $c->id, 'componentId' => (int) $c->component_id, 'name' => (string) $c->name, 'cost' => $cost,
                'contracts' => array_sum(array_column($acts, 'contracts')), 'activities' => $acts,
            ];
        }
        $out = [];
        foreach ($components as $p) {
            $cats = $catsBy[$p->id] ?? [];
            usort($cats, $byId);
            $costs = array_filter(array_column($cats, 'cost'), fn ($v) => $v !== null);
            $out[] = [
                'id' => (int) $p->id, 'name' => (string) $p->name, 'description' => (string) ($p->description ?? ''), 'isActive' => (bool) $p->is_active,
                // Montant total : somme des coûts des catégories, donc de toutes les activités ; à défaut, ancien budget saisi.
                'total' => $costs ? round(array_sum($costs), 2) : self::money($p->budget), 'totalComputed' => (bool) $costs,
                'legacyBudget' => self::money($p->budget), 'contracts' => (int) ($byComponent[$p->id] ?? 0), 'categories' => $cats,
            ];
        }
        usort($out, fn ($a, $b) => ($b['isActive'] <=> $a['isActive']) ?: $sort($a, $b));
        return $out;
    }

    /** Montant total de chaque composante (UC), par identifiant. */
    public static function totals(int $projectId): array
    {
        $out = [];
        foreach (self::load($projectId) as $c) $out[$c['id']] = $c['total'];
        return $out;
    }

    /** Activité, avec sa catégorie et sa composante, si elle appartient au projet. */
    public static function activity(int $projectId, int $activityId): ?object
    {
        if (!self::ready()) return null;
        return DB::table('component_activities as a')
            ->join('component_categories as k', 'k.id', '=', 'a.category_id')
            ->join('project_components as p', 'p.id', '=', 'k.component_id')
            ->where('a.id', $activityId)->where('p.project_id', $projectId)
            ->select('a.*', 'k.component_id', 'k.name as category_name', 'p.is_active as component_active')
            ->first();
    }
}
