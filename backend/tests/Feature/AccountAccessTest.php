<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\AccessMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Décisions du 08/11/2026 : mot de passe changé en session, demandes d'accès signalées par e-mail, phases modifiables et archives réservées. */
class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;

    private int $unit;

    private ProjectPhase $current;

    private ProjectPhase $archived;

    private const PASSWORD = 'Bonjour-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        $this->archived = ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => false, 'is_archived' => true]);
        $this->current = ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 2, 'name' => 'Phase 2', 'is_current' => true, 'is_archived' => false]);
        $this->unit = DB::table('organizational_units')->insertGetId(['project_id' => $this->prise->id, 'type' => 'COORDINATION', 'code' => 'CN', 'name' => 'Coordination nationale', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(array $attrs = []): User
    {
        $u = new User;
        $u->forceFill(array_merge(['project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u').'@example.test', 'password' => Hash::make(self::PASSWORD),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false, 'organizational_unit_id' => $this->unit], $attrs))->save();

        return $u;
    }

    private function as(User $u, array $headers = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum')->withHeaders(['X-Project' => 'PRISE'] + $headers);
    }

    public function test_changer_son_mot_de_passe_ferme_toutes_ses_sessions(): void
    {
        $agent = $this->user();
        $agent->createToken('a');
        $agent->createToken('b');

        $this->as($agent)->postJson('/api/auth/password', ['current_password' => 'Faux-2026xx', 'password' => 'Nouveau-2026', 'password_confirmation' => 'Nouveau-2026'])
            ->assertStatus(422)->assertJsonPath('message', 'Le mot de passe actuel est incorrect.');
        $this->as($agent)->postJson('/api/auth/password', ['current_password' => self::PASSWORD, 'password' => 'court1', 'password_confirmation' => 'court1'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Le mot de passe doit contenir au moins 10 caractères.');
        $this->as($agent)->postJson('/api/auth/password', ['current_password' => self::PASSWORD, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertStatus(422)->assertJsonPath('message', 'Choisissez un mot de passe différent de l’actuel.');

        $this->as($agent)->postJson('/api/auth/password', ['current_password' => self::PASSWORD, 'password' => 'Nouveau-2026', 'password_confirmation' => 'Nouveau-2026'])->assertOk();
        $this->assertSame(0, $agent->tokens()->count());
        $this->assertTrue(Hash::check('Nouveau-2026', $agent->refresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'CHANGEMENT_MOT_DE_PASSE', 'entity_id' => (string) $agent->id]);
        $this->postJson('/api/auth/login', ['email' => $agent->email, 'password' => 'Nouveau-2026', 'project' => 'PRISE'])->assertOk();
    }

    public function test_une_demande_d_acces_previent_le_super_admin_et_l_approbation_previent_le_demandeur(): void
    {
        $super = $this->user(['is_super_admin' => true, 'role' => 'ADMINISTRATEUR', 'email' => 'Super@Example.test']);
        $this->user(['email' => 'agent@example.test']);
        $this->assertSame(['super@example.test'], AccessMail::superAdminEmails($this->prise->id));

        $id = $this->postJson('/api/auth/register', ['project' => 'PRISE', 'name' => 'Nouvel agent', 'email' => 'nouveau@example.test', 'project_position' => 'Agent',
            'organizational_unit_id' => $this->unit, 'password' => 'Bienvenue-2026', 'password_confirmation' => 'Bienvenue-2026'])->assertCreated()->json('user_id');
        $this->assertDatabaseHas('user_notifications', ['user_id' => $super->id, 'kind' => 'DEMANDE_ACCES', 'entity_id' => (string) $id, 'link' => '/users', 'read_at' => null]);
        // Sans SMTP, la demande est enregistrée quand même ; l'échec de l'e-mail est journalisé.
        $this->assertStringContainsString('non envoyé', (string) DB::table('audit_logs')->where('action', 'DEMANDE_INSCRIPTION_EMAIL')->value('details'));

        $this->as($super)->postJson("/api/users/{$id}/approve")->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'prévenez-le directement'));
        $this->assertNotNull(DB::table('user_notifications')->where('kind', 'DEMANDE_ACCES')->where('entity_id', (string) $id)->value('read_at'));
        $this->assertTrue((bool) User::find($id)->is_active);
    }

    public function test_les_phases_archivees_sont_reservees_meme_aux_administrateurs(): void
    {
        $super = $this->user(['is_super_admin' => true, 'role' => 'ADMINISTRATEUR']);
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'can_manage_settings' => true]);

        // Un administrateur sans l'habilitation retombe sur la phase courante et ne voit pas les archives.
        $this->as($admin, ['X-Phase' => (string) $this->archived->id])->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('phaseId', $this->current->id)->assertJsonPath('user.permissions.canViewArchives', false)->assertJsonCount(1, 'phases');
        $this->as($admin)->getJson('/api/archives')->assertForbidden();
        $this->as($admin)->getJson('/api/archives/access')->assertOk()->assertJsonPath('granted', false)->assertJsonPath('archivedPhases', 1)->assertJsonPath('pending', false);

        // Il en fait la demande : le super-admin est notifié ; une seconde demande est refusée tant que la première est en cours.
        $this->as($admin)->postJson('/api/archives/request', ['reason' => 'Rapport annuel'])->assertOk();
        $this->assertDatabaseHas('user_notifications', ['user_id' => $super->id, 'kind' => 'DEMANDE_ARCHIVES', 'entity_id' => (string) $admin->id, 'read_at' => null]);
        $this->as($admin)->postJson('/api/archives/request')->assertStatus(422);
        $this->as($admin)->getJson('/api/archives/access')->assertJsonPath('pending', true);

        // Le super-admin accorde l'accès : la demande est close, le demandeur notifié, la phase archivée consultable.
        $this->as($super)->putJson("/api/users/{$admin->id}/permissions", ['role' => 'ADMINISTRATEUR', 'can_manage_settings' => true, 'can_view_archives' => true])->assertOk();
        $this->assertNotNull(DB::table('user_notifications')->where('kind', 'DEMANDE_ARCHIVES')->where('entity_id', (string) $admin->id)->value('read_at'));
        $this->assertDatabaseHas('user_notifications', ['user_id' => $admin->id, 'kind' => 'ACCES_ARCHIVES']);
        $admin->refresh();
        $this->as($admin, ['X-Phase' => (string) $this->archived->id])->getJson('/api/auth/me')->assertJsonPath('phaseId', $this->archived->id);
        $this->as($admin)->getJson('/api/archives')->assertOk()->assertJsonCount(1);

        // Seul le super-admin restaure une phase archivée.
        $this->as($admin)->putJson("/api/phases/{$this->archived->id}/restore")->assertForbidden();
        $this->as($super)->putJson("/api/phases/{$this->archived->id}/restore")->assertOk();
    }

    public function test_modifier_une_phase(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'can_manage_settings' => true]);
        $this->as($admin)->putJson("/api/phases/{$this->current->id}", ['number' => 1, 'name' => 'Doublon'])->assertStatus(422)->assertJsonPath('message', 'Ce numéro de phase existe déjà dans ce projet.');
        $this->as($admin)->putJson("/api/phases/{$this->current->id}", ['number' => 3, 'name' => 'Phase de consolidation', 'description' => 'Extension 2027'])->assertOk();
        $p = $this->current->refresh();
        $this->assertSame([3, 'Phase de consolidation', 'Extension 2027'], [(int) $p->number, $p->name, $p->description]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'MODIFICATION_PHASE', 'entity_id' => (string) $p->id]);

        $agent = $this->user();
        $this->as($agent)->putJson("/api/phases/{$this->current->id}", ['number' => 4, 'name' => 'X'])->assertForbidden();
    }
}
