<?php
namespace App\Http\Controllers;

use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\AlertEngine;
use App\Support\Ctx;
use App\Support\Dates;
use App\Support\Loader;
use App\Support\MailService;
use App\Support\PasswordPolicy;
use App\Support\Metrics;
use App\Support\Views;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /** Comparé quand le compte n'existe pas : la réponse prend le même temps, qu'il existe ou non. */
    private const DUMMY_HASH = '$2y$12$2Nparf51ln8xJdgi3rHnauMLDHV4qku1OK4ZUeDAm7gYLZf7QqV5W';

    /**
     * Seul message d'échec de connexion : compte inconnu, mot de passe faux, compte désactivé ou bloqué. Un message propre au
     * blocage révélerait qu'un compte existe ; pendant le blocage, il dirait même si le mot de passe essayé est le bon.
     */
    private static function generic(): string
    {
        return 'Projet, e-mail ou mot de passe incorrect, ou compte désactivé. Après ' . (int) config('prise.max_failed_logins', 5)
            . ' essais infructueux, le compte est bloqué ' . (int) config('prise.lockout_minutes', 15) . ' minutes.';
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string', 'project' => 'required|in:PRISE,PREDIRE']);
        $project = Project::where('code', $d['project'])->first();
        abort_unless($project, 422, 'Projet introuvable.');
        $u = User::whereRaw('LOWER(email) = ?', [strtolower(trim($d['email']))])->where('project_id', $project->id)->first();
        $valid = Hash::check($d['password'], $u?->password ?: self::DUMMY_HASH);
        // Compte inconnu ou bloqué : même réponse, sans compter l'essai (le blocage court jusqu'à son terme).
        if (!$u || ($u->lockout_end && $u->lockout_end->isFuture())) abort(422, self::generic());

        // Comptes migrés depuis l'application C# : PBKDF2-SHA256, 120 000 itérations (re-hachés au premier login réussi).
        if (!$valid && $u->password_hash && $u->password_salt) {
            try {
                $salt = base64_decode($u->password_salt, true);
                $expected = base64_decode($u->password_hash, true);
                $actual = $salt === false ? false : hash_pbkdf2('sha256', $d['password'], $salt, 120000, 32, true);
                $valid = is_string($actual) && is_string($expected) && hash_equals($expected, $actual);
            } catch (\Throwable $e) { $valid = false; }
            if ($valid) { $u->password = Hash::make($d['password']); $u->password_hash = null; $u->password_salt = null; }
        }
        if (!$valid) {
            $failed = (int) $u->failed_login_count + 1;
            $max = (int) config('prise.max_failed_logins', 5);
            $u->failed_login_count = $failed >= $max ? 0 : $failed;
            if ($failed >= $max) $u->lockout_end = now()->addMinutes((int) config('prise.lockout_minutes', 15));
            $u->save();
            abort(422, self::generic());
        }
        if ($u->is_pending) abort(422, 'Votre compte est en attente de validation par l’administrateur du projet.');
        if (!$u->is_active) abort(422, self::generic());

        $u->failed_login_count = 0;
        $u->lockout_end = null;
        $u->last_login_at = now();
        $u->save();
        $u->tokens()->where('expires_at', '<', now())->delete();
        $token = $u->createToken('prise-web', ['*'], now()->addDays(14))->plainTextToken;
        Ctx::audit('CONNEXION', 'User', $u->id, 'Ouverture de session web dans le projet ' . $project->code . '.', (int) $project->id, (int) $u->id);
        return ['token' => $token, 'user' => Views::user($u), 'project' => $project->code];
    }

    public function register(Request $r)
    {
        $d = $r->validate([
            'project' => 'required|in:PRISE,PREDIRE', 'name' => 'required|string|max:200', 'email' => 'required|email|max:320',
            'project_position' => 'nullable|string|max:200', 'password' => PasswordPolicy::rules(),
            'organizational_unit_id' => 'nullable|integer', 'position_id' => 'nullable|integer',
        ], PasswordPolicy::messages());
        $project = Project::where('code', $d['project'])->first();
        abort_unless($project, 422, 'Projet introuvable.');
        // Unité et poste choisis dans les listes gérées par l'administration (TDR §5), obligatoires dès qu'elles existent.
        $units = OrganizationalUnit::where('project_id', $project->id)->where('is_active', true);
        $positions = Position::where('project_id', $project->id)->where('is_active', true);
        $unitId = $d['organizational_unit_id'] ?? null;
        $positionId = $d['position_id'] ?? null;
        abort_if(!$unitId && (clone $units)->exists(), 422, 'Choisissez votre unité (coordination nationale ou pool).');
        abort_if($unitId && !(clone $units)->where('id', $unitId)->exists(), 422, 'Unité invalide pour ce projet.');
        abort_if(!$positionId && (clone $positions)->exists(), 422, 'Choisissez votre poste.');
        abort_if($positionId && !(clone $positions)->where('id', $positionId)->exists(), 422, 'Poste invalide pour ce projet.');
        abort_unless(OrganizationController::positionFitsUnit($positionId ? (int) $positionId : null, $unitId ? (int) $unitId : null), 422, 'Ce poste n’est pas prévu pour l’unité choisie.');
        $positionName = $positionId ? (string) Position::find($positionId)->name : trim((string) ($d['project_position'] ?? ''));
        abort_if($positionName === '', 422, 'Le poste est obligatoire.');
        $d['project_position'] = $positionName;
        $email = strtolower(trim($d['email']));
        if (User::where('project_id', $project->id)->whereRaw('LOWER(email)=?', [$email])->exists()) abort(422, 'Cette adresse e-mail est déjà utilisée dans ce projet.');
        $u = User::create([
            'project_id' => $project->id, 'name' => trim($d['name']), 'full_name' => trim($d['name']), 'email' => $email,
            'project_position' => $d['project_position'], 'organizational_unit_id' => $unitId, 'position_id' => $positionId, 'password' => Hash::make($d['password']), 'role' => 'UTILISATEUR', 'is_active' => false, 'is_pending' => true,
            'can_view_reports' => false, 'can_view_alerts_contracts' => false, 'can_view_alerts_guarantees' => false, 'can_view_alerts_logistics' => false, 'can_view_situation' => false,
        ]);
        Ctx::audit('DEMANDE_INSCRIPTION', 'User', $u->id, 'Demande d’inscription de ' . $email . ', en attente de validation.', (int) $project->id, (int) $u->id);
        return response()->json(['message' => 'Demande enregistrée. Votre compte ' . $project->code . ' sera activé après validation par l’administrateur du projet.', 'user_id' => $u->id], 201);
    }

    public function forgotPassword(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'project' => 'required|in:PRISE,PREDIRE']);
        $project = Project::where('code', $d['project'])->first();
        $email = strtolower(trim($d['email']));
        $user = $project ? User::where('project_id', $project->id)->whereRaw('LOWER(email)=?', [$email])->where('is_active', true)->where('is_pending', false)->first() : null;
        if ($user) {
            $recent = DB::table('user_reset_tokens')->where('user_id', $user->id)->where('created_at', '>', now()->subMinutes(2))->exists();
            if (!$recent) {
                DB::table('user_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
                $raw = bin2hex(random_bytes(32));
                $minutes = (int) config('prise.reset_token_minutes', 30);
                DB::table('user_reset_tokens')->insert([
                    'project_id' => $project->id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $raw),
                    'expires_at' => now()->addMinutes($minutes), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $link = config('prise.frontend_url') . '/reset-password?project=' . $project->code . '&token=' . $raw;
                $body = "Bonjour {$user->name},\n\nUne réinitialisation du mot de passe de votre compte {$project->code} a été demandée.\n"
                    . "Pour choisir un nouveau mot de passe, ouvrez ce lien (valable {$minutes} minutes, utilisable une seule fois) :\n\n{$link}\n\n"
                    . 'Si vous n’êtes pas à l’origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.';
                $sent = MailService::send((int) $project->id, 'PRISE — Réinitialisation de votre mot de passe', $body, [$user->email]);
                DB::table('password_reset_requests')->insert([
                    'project_id' => $project->id, 'user_id' => $user->id, 'email' => $email, 'requested_at' => now(), 'is_read_by_admin' => false,
                    'status' => $sent['success'] ? 'Lien envoyé' : 'Envoi impossible — réinitialisation manuelle requise', 'created_at' => now(), 'updated_at' => now(),
                ]);
                Ctx::audit('DEMANDE_REINITIALISATION', 'User', $user->id, $sent['success'] ? "Lien de réinitialisation envoyé à {$email}." : "Lien non envoyé à {$email} : " . $sent['message'], (int) $project->id, (int) $user->id);
            }
        }
        return ['message' => 'Si ce compte existe dans le projet sélectionné, un e-mail contenant un lien de réinitialisation vient d’être envoyé. Le lien est valable 30 minutes.'];
    }

    private function findToken(string $projectCode, ?string $raw)
    {
        if (!$raw) return null;
        $project = Project::where('code', strtoupper($projectCode))->first();
        if (!$project) return null;
        return DB::table('user_reset_tokens')->where('project_id', $project->id)->where('token_hash', hash('sha256', trim($raw)))->whereNull('used_at')->where('expires_at', '>', now())->first();
    }

    public function resetCheck(Request $r)
    {
        return ['valid' => (bool) $this->findToken((string) $r->query('project', ''), $r->query('token'))];
    }

    public function resetPassword(Request $r)
    {
        $d = $r->validate(['project' => 'required|in:PRISE,PREDIRE', 'token' => 'required|string', 'password' => PasswordPolicy::rules()], PasswordPolicy::messages());
        $t = $this->findToken($d['project'], $d['token']);
        abort_unless($t, 422, 'Lien invalide ou expiré. Demandez un nouveau lien.');
        // Le jeton n'est consommé que s'il est encore libre : une seule validation peut réussir.
        $consumed = DB::table('user_reset_tokens')->where('id', $t->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
        abort_unless($consumed, 422, 'Ce lien a déjà été utilisé.');
        $u = User::findOrFail($t->user_id);
        $u->password = Hash::make($d['password']);
        $u->password_hash = null; $u->password_salt = null; $u->failed_login_count = 0; $u->lockout_end = null;
        $u->save();
        // Le mot de passe a peut-être fui : toutes les sessions ouvertes sont fermées.
        $u->tokens()->delete();
        Ctx::audit('REINITIALISATION_MOT_DE_PASSE', 'User', $u->id, 'Mot de passe défini à l’aide d’un lien de réinitialisation à usage unique.', (int) $u->project_id, (int) $u->id);
        return ['message' => 'Votre mot de passe a été modifié et vos sessions ouvertes ont été fermées. Vous pouvez vous connecter.'];
    }

    public function me(Request $r)
    {
        $u = $r->user();
        $project = Ctx::project();
        $canArchives = Ctx::can('can_view_archives');
        $phases = ProjectPhase::where('project_id', $project->id)->orderBy('number')->get()
            ->filter(fn ($p) => !$p->is_archived || $canArchives)->values()
            ->map(fn ($p) => ['id' => $p->id, 'number' => $p->number, 'name' => $p->name, 'isCurrent' => (bool) $p->is_current, 'isArchived' => (bool) $p->is_archived]);
        return [
            'user' => Views::user($u), 'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
            'phases' => $phases, 'phaseId' => Ctx::phaseId(), 'alerts' => $this->summary($u),
        ];
    }

    /** Compteurs d'alertes de l'en-tête, selon les habilitations : contrats, garanties, logistique (de l'unité du compte), sécurité. */
    public function summary($u): array
    {
        $out = ['contracts' => 0, 'guarantees' => 0, 'logistics' => 0, 'security' => 0];
        if (Ctx::can('can_view_alerts_contracts', $u)) {
            foreach (Loader::contracts(Ctx::projectId(), Ctx::phaseId()) as $c) {
                $any100 = false;
                foreach ($c['sites'] as $s) if (Metrics::siteProgress($s) >= 100) { $any100 = true; break; }
                if (Metrics::status($c) === 'Retard' || Metrics::progress($c) >= 100 || $any100) $out['contracts']++;
            }
        }
        if (Ctx::can('can_view_alerts_guarantees', $u)) {
            foreach (Loader::guaranteeRows(['project_id' => Ctx::projectId(), 'phase_id' => Ctx::phaseId()]) as $g) if (empty($g['contract_renewed']) && Metrics::guaranteeFlags($g)['needsAttention']) $out['guarantees']++;
        }
        if (Ctx::can('can_view_alerts_logistics', $u)) $out['logistics'] = count(AlertEngine::logistics(Ctx::projectId(), Ctx::readableUnitIds($u)));
        if (Ctx::can('can_manage_users', $u)) {
            $out['security'] = (int) DB::table('password_reset_requests')->where('project_id', Ctx::projectId())->where('is_read_by_admin', false)->count()
                + (int) DB::table('users')->where('project_id', Ctx::projectId())->where('is_pending', true)->count();
        }
        return $out;
    }

    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()?->delete();
        return ['message' => 'Déconnexion réussie.'];
    }
}
