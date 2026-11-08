<?php
namespace App\Http\Controllers;

use App\Models\ProjectPhase;
use App\Support\AccessMail;
use App\Support\Ctx;
use App\Support\MailService;
use App\Support\Notifier;
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

    /** Phases archivées : réservées au super-administrateur et aux comptes qu'il autorise (décision du 08/11/2026). */
    public function archives()
    {
        abort_unless(Ctx::canViewArchives(), 403, 'Les phases archivées sont réservées. Demandez l’accès au super-administrateur.');
        return ProjectPhase::where('project_id', Ctx::projectId())->where('is_archived', true)->orderByDesc('number')->get()->map(fn ($p) => $this->view($p))->values();
    }

    /** État de l'accès aux archives du compte : accordé, demande en cours, ou à demander. */
    public function archiveAccess()
    {
        $u = Ctx::user();
        return [
            'granted' => Ctx::canViewArchives(),
            'archivedPhases' => (int) ProjectPhase::where('project_id', Ctx::projectId())->where('is_archived', true)->count(),
            'pending' => $this->pendingRequest((int) $u->id),
        ];
    }

    private function pendingRequest(int $userId): bool
    {
        return DB::table('user_notifications')->where('project_id', Ctx::projectId())->where('kind', 'DEMANDE_ARCHIVES')
            ->where('entity_name', 'User')->where('entity_id', (string) $userId)->whereNull('read_at')->exists();
    }

    /** Demande d'accès aux archives, adressée au super-administrateur dans son espace et par e-mail. */
    public function requestArchives(Request $r)
    {
        $d = $r->validate(['reason' => 'nullable|string|max:500']);
        $u = Ctx::user();
        abort_if(Ctx::canViewArchives(), 422, 'Vous avez déjà accès aux phases archivées.');
        abort_if($this->pendingRequest((int) $u->id), 422, 'Votre demande est déjà transmise au super-administrateur.');
        $reason = trim((string) ($d['reason'] ?? ''));
        $name = $u->full_name ?: $u->name;
        $superAdmins = DB::table('users')->where('project_id', Ctx::projectId())->where('is_super_admin', true)->where('is_active', true)->pluck('id')->all();
        abort_unless($superAdmins, 422, 'Aucun super-administrateur actif ne peut recevoir la demande.');
        Notifier::send($superAdmins, 'DEMANDE_ARCHIVES', 'Accès aux archives demandé par ' . $name, $reason !== '' ? 'Motif : ' . $reason : $u->email, '/users', 'User', $u->id);
        $code = Ctx::project()->code;
        $body = "Bonjour,\n\n{$name} ({$u->email}) demande l’accès aux phases archivées du projet {$code}."
            . ($reason !== '' ? "\n\nMotif : {$reason}" : '') . "\n\nPour l’accorder, cochez « Accès aux archives » dans ses habilitations, écran Utilisateurs :\n"
            . rtrim((string) config('prise.frontend_url'), '/') . "/users\n\nCe message est envoyé automatiquement par l’application de suivi des contrats.";
        try {
            $mail = MailService::send(Ctx::projectId(), "{$code} — Demande d’accès aux archives : {$name}", $body, AccessMail::superAdminEmails(Ctx::projectId()));
        } catch (\Throwable $e) {
            $mail = ['success' => false, 'message' => $e->getMessage()];
        }
        Ctx::audit('DEMANDE_ACCES_ARCHIVES', 'User', $u->id, 'Accès aux archives demandé' . ($reason !== '' ? ' (motif : ' . $reason . ')' : '') . '. '
            . ($mail['success'] ? 'Super-administrateur prévenu par e-mail.' : 'E-mail non envoyé : ' . $mail['message']));
        return ['message' => 'Votre demande a été transmise au super-administrateur. Vous serez notifié dès que l’accès sera accordé.'];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['number' => 'required|integer|min:1', 'name' => 'required|string|max:200', 'description' => 'nullable|string|max:1000']);
        abort_if(ProjectPhase::where('project_id', Ctx::projectId())->where('number', $d['number'])->exists(), 422, 'Ce numéro de phase existe déjà dans ce projet.');
        $p = ProjectPhase::create($d + ['project_id' => Ctx::projectId(), 'is_current' => false, 'is_archived' => false]);
        Ctx::audit('CREATION_PHASE', 'ProjectPhase', $p->id, 'Phase ' . $p->name . ' créée.');
        return response()->json(['message' => 'Phase créée.', 'id' => $p->id], 201);
    }

    /** Modification du numéro, du nom et de la description d'une phase. */
    public function update(Request $r, int $id)
    {
        $p = $this->phaseOr404($id);
        $d = $r->validate(['number' => 'required|integer|min:1', 'name' => 'required|string|max:200', 'description' => 'nullable|string|max:1000'],
            ['number.required' => 'Le numéro est obligatoire.', 'number.min' => 'Le numéro doit être au moins 1.', 'name.required' => 'Le nom est obligatoire.']);
        abort_if(ProjectPhase::where('project_id', Ctx::projectId())->where('number', $d['number'])->where('id', '!=', $p->id)->exists(), 422, 'Ce numéro de phase existe déjà dans ce projet.');
        $keys = ['number', 'name', 'description'];
        $before = $p->only($keys);
        $p->update(['number' => (int) $d['number'], 'name' => trim($d['name']), 'description' => $d['description'] ?? null]);
        Ctx::auditChange('MODIFICATION_PHASE', 'ProjectPhase', $p->id, 'Phase ' . $p->name . ' modifiée.', $before, $p->only($keys));
        return ['message' => 'Phase modifiée.'];
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
        // Restaurer rend la phase à tous : seul le super-administrateur, qui décide de l'accès aux archives, le peut.
        abort_unless(Ctx::user()->is_super_admin, 403, 'Seul le super-administrateur peut restaurer une phase archivée.');
        $p = $this->phaseOr404($id);
        $p->update(['is_archived' => false]);
        Ctx::audit('RESTAURATION_PHASE', 'ProjectPhase', $id, 'Phase restaurée : ' . $p->name);
        return ['message' => 'Phase restaurée.'];
    }
}
