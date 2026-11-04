<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Ctx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;


class UserController extends Controller
{
    private const FLAGS = ['can_manage_settings', 'can_manage_users', 'can_delete_contracts', 'can_manage_contracts', 'can_manage_companies', 'can_manage_progress', 'can_manage_payments', 'can_view_reports', 'can_view_alerts_contracts', 'can_view_alerts_guarantees', 'can_view_alerts_logistics', 'can_view_situation', 'can_view_archives', 'can_manage_guarantees', 'can_view_stock', 'can_manage_stock', 'can_view_fleet', 'can_manage_fleet', 'can_authorize_fleet', 'can_validate_decomptes'];

    /** Comptes visibles : ceux de son unité ; tous pour le super-admin et les comptes à vue nationale. */
    private function scoped()
    {
        $q = User::where('project_id', Ctx::projectId());
        $ids = Ctx::readableUnitIds();
        if ($ids !== null) $q->whereIn('organizational_unit_id', $ids ?: [0]);
        return $q;
    }

    private function userOr404(int $id): User
    {
        $u = $this->scoped()->where('id', $id)->first();
        abort_unless($u, 404, 'Utilisateur introuvable.');
        return $u;
    }

    /**
     * Les comptes super-administrateur ne sont modifiables que par un super-administrateur ; hors super-admin, on ne gère
     * que les comptes de son unité (la vue nationale reste en lecture seule).
     */
    public static function guard(User $u): void
    {
        abort_if($u->is_super_admin && !Ctx::user()->is_super_admin, 403, 'Compte protégé : réservé au super-administrateur.');
        abort_unless(Ctx::user()->is_super_admin || ($u->organizational_unit_id && Ctx::canWriteUnit((int) $u->organizational_unit_id)), 403,
            'Ce compte appartient à une autre unité que la vôtre.');
    }

    private function view(User $u): array
    {
        $perms = [];
        foreach (self::FLAGS as $f) $perms[lcfirst(str_replace('_', '', ucwords($f, '_')))] = (bool) $u->$f;
        return [
            'id' => $u->id, 'name' => $u->full_name ?: $u->full_name, 'email' => $u->email, 'title' => Ctx::title($u), 'position' => (string) $u->project_position, 'role' => $u->role,
            'logisticsAuthority' => $u->logistics_authority,
            'isAdmin' => Ctx::isAdmin($u),
            'isActive' => (bool) $u->is_active, 'isPending' => (bool) $u->is_pending, 'isSuperAdmin' => (bool) $u->is_super_admin, 'permissions' => $perms,
            'lastLoginAt' => $u->last_login_at ? $u->last_login_at->format('Y-m-d H:i') : null,
            'lockedUntil' => ($u->lockout_end && $u->lockout_end->isFuture()) ? $u->lockout_end->format('H:i') : null, 'createdAt' => $u->created_at ? $u->created_at->format('Y-m-d H:i') : null,
        ] + \App\Support\Views::assignment($u);
    }

    public function index()
    {
        $pid = Ctx::projectId();
        $users = $this->scoped()->orderByDesc('is_pending')->orderBy('full_name')->get();
        $resets = DB::table('password_reset_requests as p')->leftJoin('users as u', 'u.id', '=', 'p.user_id')->where('p.project_id', $pid)->orderByDesc('p.requested_at')->limit(30)
            ->when(Ctx::readableUnitIds() !== null, fn ($q) => $q->whereIn('u.organizational_unit_id', Ctx::readableUnitIds() ?: [0]))
            ->select('p.*', 'u.full_name as user_name')->get()->map(fn ($p) => [
                'id' => $p->id, 'date' => (string) $p->requested_at, 'user' => (string) ($p->user_name ?: 'Compte'), 'email' => $p->email, 'status' => (string) ($p->status ?? ''), 'isRead' => (bool) $p->is_read_by_admin,
            ])->values();
        return ['users' => $users->map(fn ($u) => $this->view($u))->values(), 'resetRequests' => $resets, 'callerId' => Ctx::user()->id, 'callerIsSuper' => (bool) Ctx::user()->is_super_admin];
    }

    public function approve(int $id)
    {
        $u = $this->userOr404($id);
        $this->guard($u);
        abort_unless($u->is_pending, 404, 'Aucune demande en attente pour ce compte.');
        $u->is_pending = false; $u->is_active = true; $u->can_view_reports = true; $u->can_view_alerts_contracts = true; $u->can_view_alerts_guarantees = true; $u->can_view_situation = true;
        $u->save();
        Ctx::audit('APPROBATION_COMPTE', 'User', $id, "Compte {$u->email} approuvé (lecture seule). Ajustez les habilitations si nécessaire.");
        return ['message' => "Compte de {$u->full_name} approuvé en lecture seule. Ajustez ses habilitations si nécessaire."];
    }

    public function reject(int $id)
    {
        $u = $this->userOr404($id);
        $this->guard($u);
        abort_unless($u->is_pending, 404, 'Aucune demande en attente pour ce compte.');
        $email = $u->email;
        $u->delete();
        Ctx::audit('REFUS_COMPTE', 'User', $id, "Demande d’inscription de {$email} refusée.");
        return ['message' => 'Demande d’inscription refusée.'];
    }

    public function toggle(int $id)
    {
        $u = $this->userOr404($id);
        $this->guard($u);
        abort_if($u->is_pending, 422, 'Ce compte est en attente : approuvez-le ou refusez-le.');
        abort_if($u->id === Ctx::user()->id || $u->is_super_admin, 422, 'Ce compte ne peut pas être désactivé depuis cet écran.');
        $u->is_active = !$u->is_active;
        $u->save();
        Ctx::audit($u->is_active ? 'REACTIVATION' : 'DESACTIVATION', 'User', $id, "Compte {$u->email}.");
        return ['message' => $u->is_active ? 'Compte réactivé.' : 'Compte désactivé.'];
    }

    /**
     * Seul le super-admin octroie les habilitations, décide si le compte est administrateur (toutes les habilitations,
     * dans son unité) ou utilisateur simple, et désigne qui consulte les pools au niveau national (lecture seule).
     */
    public function permissions(Request $r, int $id)
    {
        abort_unless(Ctx::user()->is_super_admin, 403, 'Les habilitations sont octroyées par le super-administrateur.');
        $u = $this->userOr404($id);
        abort_if($u->is_super_admin, 422, 'Le super-administrateur dispose toujours de toutes les habilitations.');
        $r->validate(['role' => 'required|in:UTILISATEUR,ADMINISTRATEUR'], ['role.required' => 'Indiquez si le compte est administrateur ou utilisateur simple.']);
        // Autorisation des demandes logistiques : une qualité par poste, selon l'unité (C.A en antenne, HRAF ou comptable à la coordination).
        $authority = $r->input('logistics_authority') ?: null;
        $unitType = $u->organizational_unit_id ? $u->organizationalUnit?->type : null;
        $allowed = $unitType === 'POOL' ? ['CA'] : ($unitType === 'COORDINATION' ? ['HRAF', 'COMPTABLE'] : []);
        if ($authority !== null && !in_array($authority, $allowed, true)) {
            throw ValidationException::withMessages(['logistics_authority' => $unitType === 'POOL'
                ? 'Dans une antenne, l’autorisation des demandes logistiques revient au C.A.'
                : ($unitType === 'COORDINATION' ? 'À la coordination nationale, l’autorisation revient au HRAF ou au comptable.' : 'Affectez d’abord ce compte à une unité.')]);
        }
        $keys = [...self::FLAGS, 'can_view_pools', 'logistics_authority', 'role'];
        $before = $u->only($keys);
        foreach (self::FLAGS as $f) $u->$f = (bool) $r->input($f, false);
        $u->logistics_authority = $authority;
        $u->can_authorize_fleet = $authority !== null;
        $u->can_view_pools = (bool) $r->input('can_view_pools', false);
        $u->role = $r->input('role');
        $u->save();
        Ctx::auditChange('HABILITATIONS', 'User', $id, "Habilitations de {$u->email} mises à jour (" . ($u->role === 'ADMINISTRATEUR' ? 'administrateur' : 'utilisateur simple')
            . ($u->can_view_pools ? ', consultation des pools au niveau national' : '') . ').', $before, $u->only($keys));
        return ['message' => 'Habilitations enregistrées.'];
    }

    /** Réinitialisation manuelle : mot de passe temporaire affiché une seule fois. */
    public function adminReset(int $id)
    {
        $u = $this->userOr404($id);
        $this->guard($u);
        abort_if($u->is_pending, 422, 'Approuvez d’abord ce compte.');
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $temp = '';
        for ($i = 0; $i < 12; $i++) $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $u->password = Hash::make($temp); $u->password_hash = null; $u->password_salt = null; $u->failed_login_count = 0; $u->lockout_end = null;
        $u->save();
        Ctx::audit('REINITIALISATION_ADMIN', 'User', $id, "Mot de passe temporaire généré pour {$u->email}.");
        return ['message' => 'Mot de passe temporaire généré. Il n’est affiché qu’une seule fois.', 'user' => $u->full_name, 'temporaryPassword' => $temp];
    }

    public function markResetRead(int $id)
    {
        $n = DB::table('password_reset_requests')->where('id', $id)->where('project_id', Ctx::projectId())->update(['is_read_by_admin' => true, 'updated_at' => now()]);
        abort_unless($n, 404, 'Notification introuvable.');
        Ctx::audit('NOTIFICATION_MDP_LUE', 'PasswordResetRequest', $id, 'Notification de réinitialisation marquée comme lue.');
        return ['message' => 'Notification marquée comme lue.'];
    }
}


