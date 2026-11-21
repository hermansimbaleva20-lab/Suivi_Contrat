<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Décision du 13/11/2026 : envoi des alertes dues sans tâche planifiée (ouverture de l'application, ou cron web avec secret). */
class AlertScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'cle-de-test-du-cron-web-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        ProjectPhase::create(['project_id' => $prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $u = new User();
        $u->forceFill([
            'project_id' => $prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => 'agent@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false,
        ])->save();
        $this->user = $u;
    }

    private User $user;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse("2026-11-13 {$time}", config('app.timezone')));
    }

    public function test_l_ouverture_de_l_application_envoie_a_partir_de_7_h_une_fois_par_heure(): void
    {
        $this->postJson('/api/alerts/auto', [], ['X-Project' => 'PRISE'])->assertUnauthorized();

        $this->actingAs($this->user, 'sanctum');
        $api = fn () => $this->postJson('/api/alerts/auto', [], ['X-Project' => 'PRISE', 'Accept' => 'application/json'])->assertOk();

        $this->at('06:40');
        $api()->assertJson(['ran' => false, 'reason' => 'AVANT_7H']);
        $this->at('07:05');
        $api()->assertJson(['ran' => true, 'reason' => 'ENVOYE']);
        $this->at('07:50');
        $api()->assertJson(['ran' => false, 'reason' => 'DEJA_FAIT']);
        $this->at('08:01');
        $api()->assertJson(['ran' => true]);
    }

    public function test_le_cron_web_exige_le_secret(): void
    {
        $this->at('09:00');
        // Sans secret configuré, l'adresse n'existe pas.
        config(['prise.cron_secret' => '']);
        $this->getJson('/api/cron/alertes?cle=')->assertNotFound();
        // Secret trop court : refusé, même s'il est fourni.
        config(['prise.cron_secret' => 'court']);
        $this->getJson('/api/cron/alertes?cle=court')->assertNotFound();

        config(['prise.cron_secret' => self::SECRET]);
        $this->getJson('/api/cron/alertes?cle=mauvais')->assertNotFound();
        $this->getJson('/api/cron/alertes?cle='.self::SECRET)->assertOk()->assertJson(['ran' => true]);
        // Même heure : rien de plus, par l'en-tête comme par le paramètre.
        $this->getJson('/api/cron/alertes', ['X-Cron-Secret' => self::SECRET])->assertOk()->assertJson(['ran' => false, 'reason' => 'DEJA_FAIT']);
    }
}
