<?php
namespace App\Http\Controllers;

use App\Models\ProjectPhase;
use App\Support\Ctx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhaseController extends Controller
{
    private function phaseOr404(int $id): ProjectPhase
    {
        $p = ProjectPhase::where('id', $id)->where('project_id', Ctx::projectId())->first();
        abort_unless($p, 404, 'Phase introuvable.');
        return $p;
    }

    private function view(ProjectPhase $p): array
    {
        return ['id' => $p->id, 'number' => $p->number, 'name' => $p->name, 'description' => (string) $p->description, 'isCurrent' => (bool) $p->is_current, 'isArchived' => (bool) $p->is_archived,
            'contracts' => (int) DB::table('contracts')->where('phase_id', $p->id)->count()];
    }

    public function index()
    {
        return ProjectPhase::where('project_id', Ctx::projectId())->orderBy('number')->get()->map(fn ($p) => $this->view($p))->values();
    }

    public function archives()
    {
        return ProjectPhase::where('project_id', Ctx::projectId())->where('is_archived', true)->orderByDesc('number')->get()->map(fn ($p) => $this->view($p))->values();
    }

    public function store(Request $r)
    {
        $d = $r->validate(['number' => 'required|integer|min:1', 'name' => 'required|string|max:200', 'description' => 'nullable|string|max:1000']);
        abort_if(ProjectPhase::where('project_id', Ctx::projectId())->where('number', $d['number'])->exists(), 422, 'Ce numéro de phase existe déjà dans ce projet.');
        $p = ProjectPhase::create($d + ['project_id' => Ctx::projectId(), 'is_current' => false, 'is_archived' => false]);
        Ctx::audit('CREATION_PHASE', 'ProjectPhase', $p->id, 'Phase ' . $p->name . ' créée.');
        return response()->json(['message' => 'Phase créée.', 'id' => $p->id], 201);
    }

    public function current(int $id)
    {
        $p = $this->phaseOr404($id);
        abort_if($p->is_archived, 422, 'Une phase archivée ne peut pas devenir active.');
        DB::transaction(function () use ($p) {
            ProjectPhase::where('project_id', Ctx::projectId())->update(['is_current' => false]);
            $p->update(['is_current' => true]);
        });
        Ctx::audit('PHASE_ACTIVE', 'ProjectPhase', $id, 'Phase active : ' . $p->name);
        return ['message' => 'Phase active mise à jour.'];
    }

    public function archive(int $id)
    {
        $p = $this->phaseOr404($id);
        abort_if($p->is_current, 422, 'La phase active ne peut pas être archivée.');
        $p->update(['is_archived' => true]);
        Ctx::audit('ARCHIVAGE_PHASE', 'ProjectPhase', $id, 'Phase archivée : ' . $p->name);
        return ['message' => 'Phase archivée.'];
    }

    public function restore(int $id)
    {
        $p = $this->phaseOr404($id);
        $p->update(['is_archived' => false]);
        Ctx::audit('RESTAURATION_PHASE', 'ProjectPhase', $id, 'Phase restaurée : ' . $p->name);
        return ['message' => 'Phase restaurée.'];
    }
}
