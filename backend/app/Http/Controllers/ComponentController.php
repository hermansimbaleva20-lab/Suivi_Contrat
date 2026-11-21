<?php

namespace App\Http\Controllers;

use App\Support\ComponentTree;
use App\Support\Ctx;
use App\Support\Dates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administration › Composantes du projet, sur le modèle de la matrice d'état d'exécution (décisions du 10 et du 16/11/2026) :
 *   composante (intitulé) → catégories à intitulé libre → activités de la catégorie, chacune avec son coût en UC
 *   (obligatoire). Le coût d'une catégorie est la somme des coûts de
 *   ses activités, et le montant total de la composante la somme des coûts de ses catégories : rien de cela ne se saisit.
 * Un contrat se rattache à une activité. Page réservée : « Gérer les paramètres » à la coordination nationale, ou super-admin ;
 * activer ou désactiver une activité est réservé au super-admin. Ce qui est utilisé par des contrats se désactive au lieu de
 * se supprimer.
 */
class ComponentController extends Controller
{
    private function need(): void
    {
        Ctx::need('can_manage_settings');
        abort_unless(Ctx::isNationalCoordination(), 403, 'Les composantes du projet sont gérées par la coordination nationale.');
    }

    private function needDetail(): void
    {
        $this->need();
        abort_unless(ComponentTree::ready(), 409, 'La base de données n’a pas encore reçu la mise à jour des catégories et activités (script mise_a_jour_2026-11-16.sql).');
    }

    /** Montant en UC facultatif ; null si vide. */
    private static function amount(Request $r, string $field, string $example): ?float
    {
        $raw = trim((string) $r->input($field, ''));
        if ($raw === '') return null;
        $v = Dates::decimal($raw);
        if ($v === null || $v < 0) throw ValidationException::withMessages([$field => "Saisissez un montant valide en UC, par exemple {$example}."]);
        return round($v, 2);
    }

    public function index()
    {
        $this->need();
        return ['components' => ComponentTree::load(Ctx::projectId()), 'detailReady' => ComponentTree::ready(), 'canToggleActivities' => (bool) Ctx::user()->is_super_admin];
    }

    /* ------------------------------------------------------------------ Composantes */

    private function componentOr404(int $id): object
    {
        $c = DB::table('project_components')->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($c, 404, 'Composante introuvable.');
        return $c;
    }

    private function componentData(Request $r, ?int $ignoreId = null): array
    {
        $pid = Ctx::projectId();
        $d = $r->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('project_components', 'name')->where('project_id', $pid)->ignore($ignoreId)],
            'description' => 'nullable|string|max:2000',
        ], ['name.unique' => 'Une composante porte déjà cet intitulé dans le projet.'], ['name' => 'intitulé']);
        return ['name' => trim($d['name']), 'description' => trim((string) ($d['description'] ?? '')) ?: null];
    }

    public function store(Request $r)
    {
        $this->need();
        $data = $this->componentData($r);
        $id = DB::table('project_components')->insertGetId($data + ['project_id' => Ctx::projectId(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Ctx::auditChange('CREATION', 'ProjectComponent', $id, "Composante « {$data['name']} » enregistrée.", null, $data);
        return response()->json(['message' => 'Composante enregistrée : ajoutez-lui maintenant ses catégories.', 'id' => $id], 201);
    }

    public function update(Request $r, int $id)
    {
        $this->need();
        $before = (array) $this->componentOr404($id);
        $data = $this->componentData($r, $id);
        DB::table('project_components')->where('id', $id)->update($data + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'ProjectComponent', $id, "Composante « {$data['name']} » modifiée.", $before, $data);
        return ['message' => 'Composante modifiée.'];
    }

    public function toggle(int $id)
    {
        $this->need();
        $c = $this->componentOr404($id);
        DB::table('project_components')->where('id', $id)->update(['is_active' => !$c->is_active, 'updated_at' => now()]);
        Ctx::auditChange($c->is_active ? 'DESACTIVATION' : 'ACTIVATION', 'ProjectComponent', $id, "Composante « {$c->name} » " . ($c->is_active ? 'désactivée.' : 'réactivée.'), ['is_active' => (bool) $c->is_active], ['is_active' => !$c->is_active]);
        return ['message' => $c->is_active ? 'Composante désactivée : ses activités ne sont plus proposées aux contrats.' : 'Composante réactivée.'];
    }

    public function destroy(int $id)
    {
        $this->need();
        $c = $this->componentOr404($id);
        abort_if(DB::table('contracts')->where('component_id', $id)->exists(), 422, 'Des contrats utilisent cette composante : désactivez-la au lieu de la supprimer.');
        DB::table('project_components')->where('id', $id)->delete(); // catégories et activités supprimées avec elle (cascade)
        Ctx::auditChange('SUPPRESSION', 'ProjectComponent', $id, "Composante « {$c->name} » supprimée avec ses catégories et activités.", (array) $c, null);
        return ['message' => 'Composante supprimée.'];
    }

    /* ------------------------------------------------------------------ Catégories */

    private function categoryOr404(int $id): object
    {
        $k = DB::table('component_categories as k')->join('project_components as p', 'p.id', '=', 'k.component_id')
            ->where('k.id', $id)->where('p.project_id', Ctx::projectId())->select('k.*', 'p.name as component_name')->first();
        abort_unless($k, 404, 'Catégorie introuvable.');
        return $k;
    }

    /**
     * Seul l'intitulé se saisit : pas de coût (somme des coûts de ses activités) ni de description (retirée le 16/11/2026 ;
     * la colonne component_categories.description n'est plus lue).
     */
    private function categoryData(Request $r, int $componentId, ?int $ignoreId = null): array
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('component_categories', 'name')->where('component_id', $componentId)->ignore($ignoreId)],
        ], ['name.unique' => 'Cette composante a déjà une catégorie de ce nom.'], ['name' => 'intitulé de la catégorie']);
        return ['name' => trim($d['name'])];
    }

    public function storeCategory(Request $r, int $componentId)
    {
        $this->needDetail();
        $p = $this->componentOr404($componentId);
        $data = $this->categoryData($r, $componentId);
        $id = DB::table('component_categories')->insertGetId($data + ['component_id' => $componentId, 'created_at' => now(), 'updated_at' => now()]);
        Ctx::auditChange('CREATION', 'ComponentCategory', $id, "Catégorie « {$data['name']} » ajoutée à la composante « {$p->name} ».", null, $data);
        return response()->json(['message' => 'Catégorie enregistrée.', 'id' => $id], 201);
    }

    public function updateCategory(Request $r, int $id)
    {
        $this->needDetail();
        $k = $this->categoryOr404($id);
        $data = $this->categoryData($r, (int) $k->component_id, $id);
        DB::table('component_categories')->where('id', $id)->update($data + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'ComponentCategory', $id, "Catégorie « {$data['name']} » de la composante « {$k->component_name} » modifiée.", ['name' => $k->name], $data);
        return ['message' => 'Catégorie modifiée.'];
    }

    public function destroyCategory(int $id)
    {
        $this->needDetail();
        $k = $this->categoryOr404($id);
        $used = DB::table('contracts')->whereIn('activity_id', DB::table('component_activities')->where('category_id', $id)->select('id'))->exists();
        abort_if($used, 422, 'Des contrats sont rattachés à des activités de cette catégorie : rattachez-les ailleurs avant de la supprimer.');
        DB::table('component_categories')->where('id', $id)->delete(); // activités supprimées avec elle (cascade)
        Ctx::auditChange('SUPPRESSION', 'ComponentCategory', $id, "Catégorie « {$k->name} » de la composante « {$k->component_name} » supprimée avec ses activités.", (array) $k, null);
        return ['message' => 'Catégorie supprimée.'];
    }

    /* ------------------------------------------------------------------ Activités */

    private function activityOr404(int $id): object
    {
        $a = ComponentTree::activity(Ctx::projectId(), $id);
        abort_unless($a, 404, 'Activité introuvable.');
        return $a;
    }

    /** Intitulé et coût en UC, obligatoire : il fait le coût de la catégorie et le montant de la composante (16/11/2026). */
    private function activityData(Request $r): array
    {
        $d = $r->validate(['name' => 'required|string|max:500'], [], ['name' => 'intitulé de l’activité']);
        $cost = self::amount($r, 'budget', '12 010 331');
        if ($cost === null) throw ValidationException::withMessages(['budget' => 'Saisissez le coût de l’activité en UC, par exemple 12 010 331.']);
        return ['name' => trim($d['name']), 'budget' => $cost];
    }

    public function storeActivity(Request $r, int $categoryId)
    {
        $this->needDetail();
        $k = $this->categoryOr404($categoryId);
        $data = $this->activityData($r);
        $id = DB::table('component_activities')->insertGetId($data + ['category_id' => $categoryId, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Ctx::auditChange('CREATION', 'ComponentActivity', $id, "Activité « {$data['name']} » ajoutée à la catégorie « {$k->name} » ({$k->component_name}).", null, $data);
        return response()->json(['message' => 'Activité enregistrée : son coût s’ajoute à celui de la catégorie.', 'id' => $id], 201);
    }

    public function updateActivity(Request $r, int $id)
    {
        $this->needDetail();
        $a = $this->activityOr404($id);
        $data = $this->activityData($r);
        DB::table('component_activities')->where('id', $id)->update($data + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'ComponentActivity', $id, "Activité « {$data['name']} » modifiée.", ['name' => $a->name, 'budget' => $a->budget], $data);
        return ['message' => 'Activité modifiée.'];
    }

    /** Activer ou désactiver une activité : réservé au super-admin. Désactivée, elle n'est plus proposée aux nouveaux contrats. */
    public function toggleActivity(int $id)
    {
        $this->needDetail();
        abort_unless(Ctx::user()->is_super_admin, 403, 'Activer ou désactiver une activité est réservé au super-administrateur.');
        $a = $this->activityOr404($id);
        DB::table('component_activities')->where('id', $id)->update(['is_active' => !$a->is_active, 'updated_at' => now()]);
        Ctx::auditChange($a->is_active ? 'DESACTIVATION' : 'ACTIVATION', 'ComponentActivity', $id, "Activité « {$a->name} » " . ($a->is_active ? 'désactivée.' : 'réactivée.'), ['is_active' => (bool) $a->is_active], ['is_active' => !$a->is_active]);
        return ['message' => $a->is_active ? 'Activité désactivée : elle n’est plus proposée aux contrats ; ceux qui y sont rattachés la gardent.' : 'Activité réactivée.'];
    }

    public function destroyActivity(int $id)
    {
        $this->needDetail();
        $a = $this->activityOr404($id);
        abort_if(DB::table('contracts')->where('activity_id', $id)->exists(), 422, 'Des contrats sont rattachés à cette activité : demandez au super-administrateur de la désactiver au lieu de la supprimer.');
        DB::table('component_activities')->where('id', $id)->delete();
        Ctx::auditChange('SUPPRESSION', 'ComponentActivity', $id, "Activité « {$a->name} » supprimée.", (array) $a, null);
        return ['message' => 'Activité supprimée.'];
    }
}
