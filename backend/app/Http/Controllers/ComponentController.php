<?php

namespace App\Http\Controllers;

use App\Support\Ctx;
use App\Support\Dates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administration › Composantes du projet (décision du 10/11/2026) : intitulé et budget en UC de chaque composante,
 * colonnes « Composantes » et « Budget en millions d'UC » de la matrice d'exécution. Enregistrées au préalable ; un contrat
 * en choisit une, facultativement. Page réservée : « Gérer les paramètres » à la coordination nationale, ou super-admin.
 * Une composante utilisée par des contrats se désactive, elle ne se supprime pas.
 */
class ComponentController extends Controller
{
    private function need(): void
    {
        Ctx::need('can_manage_settings');
        abort_unless(Ctx::isNationalCoordination(), 403, 'Les composantes du projet sont gérées par la coordination nationale.');
    }

    private function view(object $c, int $contracts): array
    {
        return ['id' => (int) $c->id, 'name' => $c->name, 'budget' => $c->budget === null ? null : (float) $c->budget, 'description' => (string) ($c->description ?? ''),
            'isActive' => (bool) $c->is_active, 'contracts' => $contracts];
    }

    public function index()
    {
        $this->need();
        $pid = Ctx::projectId();
        $used = DB::table('contracts')->where('project_id', $pid)->whereNotNull('component_id')->groupBy('component_id')->selectRaw('component_id, COUNT(*) AS n')->pluck('n', 'component_id');
        return DB::table('project_components')->where('project_id', $pid)->orderByDesc('is_active')->orderBy('name')->get()
            ->map(fn ($c) => $this->view($c, (int) ($used[$c->id] ?? 0)))->values();
    }

    private function data(Request $r, ?int $ignoreId = null): array
    {
        $pid = Ctx::projectId();
        $d = $r->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('project_components', 'name')->where('project_id', $pid)->ignore($ignoreId)],
            'description' => 'nullable|string|max:2000',
        ], ['name.unique' => 'Une composante porte déjà cet intitulé dans le projet.'], ['name' => 'intitulé']);
        $raw = trim((string) $r->input('budget', ''));
        $budget = $raw === '' ? null : Dates::decimal($raw);
        if ($raw !== '' && ($budget === null || $budget < 0)) throw ValidationException::withMessages(['budget' => 'Saisissez un budget valide, par exemple 12010331.']);
        return ['name' => trim($d['name']), 'budget' => $budget === null ? null : round($budget, 2), 'description' => trim((string) ($d['description'] ?? '')) ?: null];
    }

    private function findOr404(int $id): object
    {
        $c = DB::table('project_components')->where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($c, 404, 'Composante introuvable.');
        return $c;
    }

    public function store(Request $r)
    {
        $this->need();
        $data = $this->data($r);
        $id = DB::table('project_components')->insertGetId($data + ['project_id' => Ctx::projectId(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        Ctx::auditChange('CREATION', 'ProjectComponent', $id, "Composante « {$data['name']} » enregistrée.", null, $data);
        return response()->json(['message' => 'Composante enregistrée.', 'id' => $id], 201);
    }

    public function update(Request $r, int $id)
    {
        $this->need();
        $before = (array) $this->findOr404($id);
        $data = $this->data($r, $id);
        DB::table('project_components')->where('id', $id)->update($data + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'ProjectComponent', $id, "Composante « {$data['name']} » modifiée.", $before, $data);
        return ['message' => 'Composante modifiée.'];
    }

    public function toggle(int $id)
    {
        $this->need();
        $c = $this->findOr404($id);
        DB::table('project_components')->where('id', $id)->update(['is_active' => !$c->is_active, 'updated_at' => now()]);
        Ctx::auditChange($c->is_active ? 'DESACTIVATION' : 'ACTIVATION', 'ProjectComponent', $id, "Composante « {$c->name} » " . ($c->is_active ? 'désactivée.' : 'réactivée.'), ['is_active' => (bool) $c->is_active], ['is_active' => !$c->is_active]);
        return ['message' => $c->is_active ? 'Composante désactivée.' : 'Composante réactivée.'];
    }

    public function destroy(int $id)
    {
        $this->need();
        $c = $this->findOr404($id);
        abort_if(DB::table('contracts')->where('component_id', $id)->exists(), 422, 'Des contrats utilisent cette composante : désactivez-la au lieu de la supprimer.');
        DB::table('project_components')->where('id', $id)->delete();
        Ctx::auditChange('SUPPRESSION', 'ProjectComponent', $id, "Composante « {$c->name} » supprimée.", (array) $c, null);
        return ['message' => 'Composante supprimée.'];
    }
}
