<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Xlsx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

/** Sécurité avant mise en ligne : sessions révoquées, connexion sans indice, limites de débit, en-têtes, CORS, import Excel. */
class SecurityApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;

    private const PASSWORD = 'Bonjour-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $this->unit = DB::table('organizational_units')->insertGetId(['project_id' => $this->prise->id, 'type' => 'COORDINATION', 'code' => 'CN', 'name' => 'Coordination nationale', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private int $unit;

    private function user(array $attrs = []): User
    {
        $u = new User;
        $u->forceFill(array_merge(['project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u').'@example.test', 'password' => Hash::make(self::PASSWORD),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false, 'organizational_unit_id' => $this->unit], $attrs))->save();

        return $u;
    }

    private function login(string $email, string $password = self::PASSWORD)
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password, 'project' => 'PRISE']);
    }

    /** Requête avec un vrai jeton, sans réutiliser l'utilisateur gardé en mémoire par la requête précédente. */
    private function bearer(string $token, string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Project' => 'PRISE', 'Accept' => 'application/json'])->json($method, $uri, $data);
    }

    public function test_un_compte_desactive_perd_l_acces_tout_de_suite(): void
    {
        $agent = $this->user();
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'can_manage_users' => true]);
        $token = $this->login($agent->email)->assertOk()->json('token');
        $adminToken = $this->login($admin->email)->assertOk()->json('token');
        $this->bearer($token, 'GET', '/api/auth/me')->assertOk();

        $this->bearer($adminToken, 'POST', "/api/users/{$agent->id}/toggle")->assertOk();
        $this->assertSame(0, $agent->tokens()->count());
        $this->bearer($token, 'GET', '/api/auth/me')->assertUnauthorized();

        // Même désactivé hors de l'écran Utilisateurs (directement en base), il est refusé et son jeton supprimé.
        $agent->refresh()->forceFill(['is_active' => true])->save();
        $token = $this->login($agent->email)->assertOk()->json('token');
        $agent->refresh()->forceFill(['is_active' => false])->save();
        $this->bearer($token, 'GET', '/api/dashboard')->assertUnauthorized()->assertJsonPath('message', 'Votre compte est désactivé. Contactez l’administrateur du projet.');
        $this->assertSame(0, $agent->tokens()->count());
    }

    public function test_changer_le_mot_de_passe_ferme_toutes_les_sessions(): void
    {
        $agent = $this->user();
        $this->login($agent->email)->assertOk();
        $this->login($agent->email)->assertOk();
        $this->assertSame(2, $agent->tokens()->count());

        $raw = bin2hex(random_bytes(32));
        DB::table('user_reset_tokens')->insert(['project_id' => $this->prise->id, 'user_id' => $agent->id, 'token_hash' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/auth/reset-password', ['project' => 'PRISE', 'token' => $raw, 'password' => 'court1', 'password_confirmation' => 'court1'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Le mot de passe doit contenir au moins 10 caractères.');
        $this->postJson('/api/auth/reset-password', ['project' => 'PRISE', 'token' => $raw, 'password' => 'Nouveau-2026', 'password_confirmation' => 'Nouveau-2026'])->assertOk();
        $this->assertSame(0, $agent->tokens()->count());

        // Mot de passe temporaire donné par l'administrateur : même effet.
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'can_manage_users' => true]);
        $this->login($agent->email, 'Nouveau-2026')->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Project' => 'PRISE'])->postJson("/api/users/{$agent->id}/admin-reset")->assertOk();
        $this->assertSame(0, $agent->tokens()->count());
    }

    public function test_la_connexion_ne_revele_ni_un_compte_ni_son_blocage(): void
    {
        $agent = $this->user();
        $inconnu = $this->login('personne@example.test')->assertStatus(422)->json('message');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($inconnu, $this->login($agent->email, 'Mauvais-2026')->assertStatus(422)->json('message'));
        }
        // Bloqué : même message, y compris avec le bon mot de passe, qui ne doit pas pouvoir être reconnu pendant le blocage.
        $this->assertSame($inconnu, $this->login($agent->email)->assertStatus(422)->json('message'));
        $this->assertStringContainsString('bloqué 15 minutes', $inconnu);

        $this->travel(16)->minutes();
        $this->login($agent->email)->assertOk();
    }

    public function test_limites_de_debit_des_routes_publiques(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->login("essai{$i}@example.test", 'Mauvais-2026')->assertStatus(422);
        }
        $this->login('encore@example.test', 'Mauvais-2026')->assertStatus(429)->assertJsonPath('message', 'Trop de tentatives depuis cette connexion. Réessayez dans quelques minutes.');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password', ['project' => 'PRISE', 'email' => "x{$i}@example.test"])->assertOk();
        }
        $this->postJson('/api/auth/forgot-password', ['project' => 'PRISE', 'email' => 'y@example.test'])->assertStatus(429);
    }

    public function test_inscription_exige_un_mot_de_passe_solide(): void
    {
        $form = ['project' => 'PRISE', 'name' => 'Nouvel agent', 'email' => 'nouveau@example.test', 'project_position' => 'Agent', 'organizational_unit_id' => $this->unit];
        $this->postJson('/api/auth/register', $form + ['password' => 'motdepasse', 'password_confirmation' => 'motdepasse'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Le mot de passe doit contenir au moins une lettre et un chiffre.');
        $this->postJson('/api/auth/register', $form + ['password' => 'Bienvenue-2026', 'password_confirmation' => 'Bienvenue-2026'])->assertCreated();
    }

    public function test_en_tetes_de_securite_et_cors_limite_au_frontend(): void
    {
        $r = $this->login('personne@example.test');
        $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString("default-src 'none'", $r->headers->get('Content-Security-Policy'));
        $this->assertFalse($r->headers->has('X-Powered-By'));

        $preflight = fn (string $origin) => $this->call('OPTIONS', '/api/auth/login', [], [], [], ['HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $this->assertSame(rtrim((string) config('prise.frontend_url'), '/'), $preflight(rtrim((string) config('prise.frontend_url'), '/'))->headers->get('Access-Control-Allow-Origin'));
        // Avec une seule origine autorisée, Laravel la renvoie toujours : le navigateur refuse alors la réponse au site étranger.
        $this->assertNotSame('https://site-malveillant.example', $preflight('https://site-malveillant.example')->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $preflight('https://site-malveillant.example')->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_un_classeur_piege_est_refuse_avant_decompression(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData>'.str_repeat(' ', Xlsx::READ_LIMIT + 1).'</sheetData></worksheet>');
        $zip->close();
        $this->assertLessThan(200000, filesize($path));
        try {
            Xlsx::read($path);
            $this->fail('Le classeur aurait dû être refusé.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fichier Excel trop volumineux une fois décompressé.', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_verification_de_la_configuration_avant_mise_en_ligne(): void
    {
        $this->assertSame(1, Artisan::call('prise:verifier-securite'));
        $this->assertStringContainsString('APP_DEBUG=false', Artisan::output());

        config(['app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://api.prise.example', 'prise.frontend_url' => 'https://suivi.prise.example',
            'database.connections.mysql.username' => 'prise_app', 'database.connections.mysql.password' => 'Mot-de-passe-long-2026',
            'cors.allowed_origins' => ['https://suivi.prise.example'], 'logging.channels.'.config('logging.default').'.level' => 'warning']);
        $this->assertSame(0, Artisan::call('prise:verifier-securite'), Artisan::output());
    }
}
