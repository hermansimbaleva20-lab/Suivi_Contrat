<?php

namespace App\Http\Controllers;

use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Project;
use App\Models\User;
use App\Support\Ctx;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Référentiel organisationnel (TDR §5) : coordination nationale, pools et postes, administrables par projet.
 * Rien n'est codé en dur ; un élément utilisé par des comptes est désactivé plutôt que supprimé.
 */
class OrganizationController extends Controller
{
    private function unitView(OrganizationalUnit $u): array
    {
        return [
            'id' => $u->id, 'type' => $u->type, 'code' => $u->code, 'name' => $u->name, 'province' => (string) $u->province,
            'description' => (string) $u->description, 'isActive' => (bool) $u->is_active,
            'usersCount' => User::where('organizational_unit_id', $u->id)->count(),
        ];
    }

    private function positionView(Position $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'description' => (string) $p->description, 'isActive' => (bool) $p->is_active,
            'unitId' => $p->organizational_unit_id ? (int) $p->organizational_unit_id : null,
            'usersCount' => User::where('position_id', $p->id)->count(),
        ];
    }

    private function unitOr404(int $id): OrganizationalUnit
    {
        $u = OrganizationalUnit::where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($u, 404, 'Unité organisationnelle introuvable.');
        return $u;
    }

    private function positionOr404(int $id): Position
    {
        $p = Position::where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($p, 404, 'Poste introuvable.');
        return $p;
    }

    private static function snapshot($model): array
    {
        return collect($model->getAttributes())->except(['created_at', 'updated_at'])->all();
    }

    public function index()
    {
        $pid = Ctx::projectId();
        return [
            'units' => OrganizationalUnit::where('project_id', $pid)->orderByRaw("CASE WHEN type = 'COORDINATION' THEN 0 ELSE 1 END")->orderBy('name')->get()->map(fn ($u) => $this->unitView($u))->values(),
            'positions' => Position::where('project_id', $pid)->orderBy('name')->get()->map(fn ($p) => $this->positionView($p))->values(),
        ];
    }

    /** Listes actives proposées à l'inscription (TDR §5) : uniquement noms et identifiants, sans donnée personnelle. */
    public function registrationOptions(Request $r)
    {
        $d = $r->validate(['project' => 'required|in:PRISE,PREDIRE']);
        $project = Project::where('code', $d['project'])->first();
        abort_unless($project, 422, 'Projet introuvable.');
        return [
            'units' => OrganizationalUnit::where('project_id', $project->id)->where('is_active', true)->orderByRaw("CASE WHEN type = 'COORDINATION' THEN 0 ELSE 1 END")->orderBy('name')
                ->get(['id', 'type', 'name'])->values(),
            // Chaque poste porte son unité : le formulaire ne propose que les postes de l'unité choisie (et les postes communs).
            'positions' => Position::where('project_id', $project->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'organizational_unit_id'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'unitId' => $p->organizational_unit_id ? (int) $p->organizational_unit_id : null])->values(),
        ];
    }

    /** Le poste appartient-il à l'unité ? Un poste sans unité (antérieur au rattachement) vaut pour toutes les unités. */
    public static function positionFitsUnit(?int $positionId, ?int $unitId): bool
    {
        if (!$positionId) return true;
        $p = Position::find($positionId);
        return $p && ($p->organizational_unit_id === null || (int) $p->organizational_unit_id === (int) $unitId);
    }

    private function unitRules(?int $ignoreId = null): array
    {
        return [
            'type' => ['required', Rule::in([OrganizationalUnit::COORDINATION, OrganizationalUnit::POOL])],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('organizational_units', 'code')->where('project_id', Ctx::projectId())->ignore($ignoreId)],
            'name' => 'required|string|max:150',
            'province' => 'nullable|string|max:150',
            'description' => 'nullable|string|max:1000',
        ];
    }

    private static function unitMessages(): array
    {
        return [
            'code.unique' => 'Ce code est déjà utilisé par une autre unité de ce projet.',
            'code.regex' => 'Le code ne peut contenir que des lettres, chiffres, tirets et soulignés.',
        ];
    }

    public function storeUnit(Request $r)
    {
        $d = $r->validate($this->unitRules(), self::unitMessages());
        $d['code'] = strtoupper($d['code']);
        $u = OrganizationalUnit::create($d + ['project_id' => Ctx::projectId(), 'is_active' => true]);
        Ctx::auditChange('CREATION_UNITE', 'OrganizationalUnit', $u->id, "Unité {$u->name} ({$u->type}) créée.", null, self::snapshot($u));
        return response()->json(['message' => 'Unité créée.', 'unit' => $this->unitView($u)], 201);
    }

    public function updateUnit(Request $r, int $id)
    {
        $u = $this->unitOr404($id);
        $d = $r->validate($this->unitRules($u->id), self::unitMessages());
        $d['code'] = strtoupper($d['code']);
        $before = self::snapshot($u);
        $u->fill($d)->save();
        Ctx::auditChange('MODIFICATION_UNITE', 'OrganizationalUnit', $u->id, "Unité {$u->name} modifiée.", $before, self::snapshot($u));
        return ['message' => 'Unité enregistrée.', 'unit' => $this->unitView($u)];
    }

    public function toggleUnit(int $id)
    {
        $u = $this->unitOr404($id);
        $before = self::snapshot($u);
        $u->is_active = !$u->is_active;
        $u->save();
        Ctx::auditChange($u->is_active ? 'ACTIVATION_UNITE' : 'DESACTIVATION_UNITE', 'OrganizationalUnit', $u->id, "Unité {$u->name}.", $before, self::snapshot($u));
        return ['message' => $u->is_active ? 'Unité réactivée.' : 'Unité désactivée. Ses comptes restent rattachés mais elle n’est plus proposée.', 'unit' => $this->unitView($u)];
    }

    public function destroyUnit(int $id)
    {
        $u = $this->unitOr404($id);
        abort_if(User::where('organizational_unit_id', $u->id)->exists(), 422, 'Des comptes sont rattachés à cette unité : désactivez-la plutôt que de la supprimer.');
        $before = self::snapshot($u);
        $u->delete();
        Ctx::auditChange('SUPPRESSION_UNITE', 'OrganizationalUnit', $id, "Unité {$before['name']} supprimée (aucun compte rattaché).", $before, null);
        return ['message' => 'Unité supprimée.'];
    }

    private function positionRules(Request $r, ?int $ignoreId = null): array
    {
        $pid = Ctx::projectId();
        $unitId = $r->filled('organizational_unit_id') ? (int) $r->input('organizational_unit_id') : null;
        return [
            'organizational_unit_id' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')->where('project_id', $pid)],
            'name' => ['required', 'string', 'max:150', Rule::unique('positions', 'name')->where('project_id', $pid)->where('organizational_unit_id', $unitId)->ignore($ignoreId)],
            'description' => 'nullable|string|max:1000',
        ];
    }

    private const POSITION_MESSAGES = ['name.unique' => 'Ce poste existe déjà dans cette unité.', 'organizational_unit_id.exists' => 'Cette unité n’appartient pas au projet.'];

    public function storePosition(Request $r)
    {
        $d = $r->validate($this->positionRules($r), self::POSITION_MESSAGES);
        $p = Position::create($d + ['project_id' => Ctx::projectId(), 'is_active' => true]);
        Ctx::auditChange('CREATION_POSTE', 'Position', $p->id, "Poste {$p->name} créé.", null, self::snapshot($p));
        return response()->json(['message' => 'Poste créé.', 'position' => $this->positionView($p)], 201);
    }

    public function updatePosition(Request $r, int $id)
    {
        $p = $this->positionOr404($id);
        $d = $r->validate($this->positionRules($r, $p->id), self::POSITION_MESSAGES);
        $d['organizational_unit_id'] = $d['organizational_unit_id'] ?? null;
        $before = self::snapshot($p);
        $p->fill($d)->save();
        Ctx::auditChange('MODIFICATION_POSTE', 'Position', $p->id, "Poste {$p->name} modifié.", $before, self::snapshot($p));
        return ['message' => 'Poste enregistré.', 'position' => $this->positionView($p)];
    }

    public function togglePosition(int $id)
    {
        $p = $this->positionOr404($id);
        $before = self::snapshot($p);
        $p->is_active = !$p->is_active;
        $p->save();
        Ctx::auditChange($p->is_active ? 'ACTIVATION_POSTE' : 'DESACTIVATION_POSTE', 'Position', $p->id, "Poste {$p->name}.", $before, self::snapshot($p));
        return ['message' => $p->is_active ? 'Poste réactivé.' : 'Poste désactivé.', 'position' => $this->positionView($p)];
    }

    public function destroyPosition(int $id)
    {
        $p = $this->positionOr404($id);
        abort_if(User::where('position_id', $p->id)->exists(), 422, 'Des comptes occupent ce poste : désactivez-le plutôt que de le supprimer.');
        $before = self::snapshot($p);
        $p->delete();
        Ctx::auditChange('SUPPRESSION_POSTE', 'Position', $id, "Poste {$before['name']} supprimé (aucun compte rattaché).", $before, null);
        return ['message' => 'Poste supprimé.'];
    }

    /** Affectation d'un compte à une unité et un poste du projet (gestion des utilisateurs). */
    public function assign(Request $r, int $userId)
    {
        $pid = Ctx::projectId();
        $user = User::where('id', $userId)->where('project_id', $pid)->first();
        abort_unless($user, 404, 'Utilisateur introuvable.');
        UserController::guard($user);
        $d = $r->validate([
            'organizational_unit_id' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')->where('project_id', $pid)],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')->where('project_id', $pid)],
        ], [
            'organizational_unit_id.exists' => 'Cette unité n’appartient pas au projet.',
            'position_id.exists' => 'Ce poste n’appartient pas au projet.',
        ]);
        abort_unless(Ctx::user()->is_super_admin || (!empty($d['organizational_unit_id']) && Ctx::canWriteUnit((int) $d['organizational_unit_id'])), 403,
            'Seul le super-administrateur affecte un compte à une autre unité que la sienne.');
        if (!self::positionFitsUnit($d['position_id'] ?? null, $d['organizational_unit_id'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['position_id' => 'Ce poste n’est pas prévu pour l’unité choisie.']);
        }
        $before = ['organizational_unit_id' => $user->organizational_unit_id, 'position_id' => $user->position_id];
        $user->organizational_unit_id = $d['organizational_unit_id'] ?? null;
        $user->position_id = $d['position_id'] ?? null;
        if ($user->position_id) $user->project_position = Position::find($user->position_id)->name;
        $user->save();
        Ctx::auditChange('AFFECTATION_UTILISATEUR', 'User', $user->id, "Affectation de {$user->email} mise à jour.", $before,
            ['organizational_unit_id' => $user->organizational_unit_id, 'position_id' => $user->position_id]);
        return ['message' => 'Affectation enregistrée.'];
    }
}
