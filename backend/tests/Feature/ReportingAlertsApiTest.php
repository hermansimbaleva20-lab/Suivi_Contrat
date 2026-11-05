<?php

namespace Tests\Feature;

use App\Models\OrganizationalUnit;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\StockArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** États figés et comparaisons ; alertes par e-mail réglées par le super-admin (destinataires, séquence, simulation). */
class ReportingAlertsApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private ProjectPhase $phase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        $this->phase = ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        foreach (['RETARD', 'GARANTIE', 'DECOMPTE', 'STOCK', 'CHARROI', 'GROUPE'] as $cat) {
            DB::table('alert_rules')->insert(['project_id' => $this->prise->id, 'category' => $cat, 'is_enabled' => true, 'interval_days' => 7, 'max_sends' => 3, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function user(array $attrs = []): User
    {
        $u = new User();
        $u->forceFill(array_merge([
            'project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false,
        ], $attrs))->save();
        return $u;
    }

    private function as(User $u): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    private function contract(): int
    {
        $co = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'Entreprise ' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('contracts')->insertGetId(['project_id' => $this->prise->id, 'phase_id' => $this->phase->id, 'company_id' => $co, 'reference' => 'C-' . uniqid(), 'category' => 'TRAVAUX',
            'amount' => 1000, 'initial_financing_rate' => 20, 'start_date' => '2025-01-01', 'end_date' => '2025-07-01', 'duration_months' => 6, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_figer_comparer_et_exporter_un_etat(): void
    {
        $this->contract();
        $lecteur = $this->user(['can_view_reports' => true]);
        $admin = $this->user(['can_manage_settings' => true, 'can_view_reports' => true]);

        $p = $this->as($lecteur)->getJson('/api/report-snapshots/preview?period_type=TRIMESTRE&year=2026&index=1')->assertOk()->json();
        $this->assertSame('T1 2026', $p['label']);
        $this->assertEquals(20, $p['rows'][0]['paymentRate']); // avance de démarrage de 20 %
        $this->as($lecteur)->postJson('/api/report-snapshots', ['period_type' => 'TRIMESTRE', 'year' => 2026, 'index' => 1])->assertForbidden();

        $t1 = $this->as($admin)->postJson('/api/report-snapshots', ['period_type' => 'TRIMESTRE', 'year' => 2026, 'index' => 1, 'comment' => 'Premier trimestre'])->assertOk()->json('id');
        $this->as($admin)->postJson('/api/report-snapshots', ['period_type' => 'TRIMESTRE', 'year' => 2026, 'index' => 1])->assertStatus(422);
        $this->as($admin)->postJson('/api/report-snapshots', ['period_type' => 'TRIMESTRE', 'year' => 2030, 'index' => 1])->assertStatus(422);
        $t2 = $this->as($admin)->postJson('/api/report-snapshots', ['period_type' => 'TRIMESTRE', 'year' => 2026, 'index' => 2])->assertOk()->json('id');

        // L'état figé ne change plus quand les données changent.
        DB::table('contracts')->update(['amount' => 5000]);
        $this->assertEquals(1000, $this->as($lecteur)->getJson("/api/report-snapshots/{$t1}")->json('rows.0.amount'));

        $cmp = $this->as($lecteur)->getJson("/api/report-snapshots/compare?a={$t1}&b={$t2}")->assertOk()->json();
        $this->assertSame('T1 2026', $cmp['a']['label']);
        $this->assertArrayHasKey('progressDelta', $cmp['rows'][0]);
        $this->as($lecteur)->getJson("/api/report-snapshots/compare?a={$t1}&b=live")->assertOk();

        $x = $this->as($lecteur)->get("/api/report-snapshots/export?id={$t1}&columns[]=number&columns[]=progress&columns[]=inconnue");
        $x->assertOk();
        $this->assertStringStartsWith('PK', $x->getContent());

        $this->as($admin)->deleteJson("/api/report-snapshots/{$t1}", ['reason' => 'Doublon de test'])->assertForbidden();
        $this->as($this->user(['is_super_admin' => true]))->deleteJson("/api/report-snapshots/{$t1}", ['reason' => 'Doublon de test'])->assertOk();
    }

    public function test_destinataires_et_sequence_reserves_au_super_admin(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR']);
        $super = $this->user(['is_super_admin' => true]);
        $pool = OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => 'POOL', 'code' => 'KGA', 'name' => 'Antenne KANANGA', 'is_active' => true]);

        $this->as($admin)->getJson('/api/alert-settings')->assertForbidden();
        $this->as($super)->getJson('/api/alert-settings')->assertOk();

        $poste = DB::table('positions')->insertGetId(['project_id' => $this->prise->id, 'name' => 'LOGISTICIEN', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($super)->postJson('/api/alert-settings/recipients', ['email' => 'sans.poste@example.test', 'name' => 'X', 'categories' => ['RETARD']])->assertStatus(422)->assertJsonValidationErrors('position_id');
        $this->as($super)->postJson('/api/alert-settings/recipients', ['email' => 'Logistique@Example.test', 'name' => 'Logistique', 'position_id' => $poste, 'categories' => ['GARANTIE']])->assertOk();
        $this->as($super)->postJson('/api/alert-settings/recipients', ['email' => 'logistique@example.test', 'name' => 'X', 'position_id' => $poste, 'categories' => ['GARANTIE']])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->as($super)->postJson('/api/alert-settings/recipients', ['email' => 'raf@example.test', 'name' => 'RAF', 'position_id' => $poste, 'categories' => []])->assertStatus(422)->assertJsonValidationErrors('categories');
        $this->as($super)->postJson('/api/alert-settings/recipients', ['email' => 'raf@example.test', 'name' => 'RAF', 'position_id' => $poste, 'categories' => ['RETARD', 'INCONNUE']])->assertStatus(422);
        // Les alertes logistiques restent dans l'application : ni destinataire, ni séquence d'envoi.
        foreach (['STOCK', 'CHARROI', 'GROUPE'] as $logistics) {
            $this->as($super)->postJson('/api/alert-settings/recipients', ['email' => 'raf@example.test', 'name' => 'RAF', 'position_id' => $poste, 'categories' => [$logistics]])->assertStatus(422);
            $this->as($super)->putJson('/api/alert-settings/rules', ['rules' => [['category' => $logistics, 'is_enabled' => true, 'interval_days' => 3, 'max_sends' => 2]]])->assertStatus(422);
        }
        $this->as($super)->postJson('/api/alert-settings/recipients', ['email' => 'raf@example.test', 'name' => 'RAF', 'position_id' => $poste, 'categories' => ['RETARD']])->assertOk();

        $this->as($super)->putJson('/api/alert-settings/rules', ['rules' => [['category' => 'RETARD', 'is_enabled' => true, 'interval_days' => 3, 'max_sends' => 2]]])->assertOk();
        $this->assertSame(3, (int) DB::table('alert_rules')->where('category', 'RETARD')->value('interval_days'));
        // Un ancien destinataire inscrit au stock ne reçoit plus rien pour la logistique.
        DB::table('alert_recipients')->where('email', 'logistique@example.test')->update(['categories' => 'STOCK,CHARROI,GROUPE']);

        // Article en rupture à Kananga, contrat en retard : simulation sans envoi.
        $a = StockArticle::create(['project_id' => $this->prise->id, 'number' => 1, 'designation' => 'Cartouche', 'unit' => 'Pce', 'unit_price' => 45, 'alert_threshold' => 5, 'is_active' => true]);
        DB::table('stock_movements')->insert(['project_id' => $this->prise->id, 'organizational_unit_id' => $pool->id, 'stock_article_id' => $a->id, 'movement_date' => '2026-10-01', 'type' => 'ENTREE', 'quantity' => 2, 'origin_destination' => 'X', 'created_at' => now(), 'updated_at' => now()]);
        $this->contract();
        $plan = $this->as($super)->postJson('/api/alert-settings/run', ['dry_run' => true])->assertOk()->json('plan');
        $byCat = collect($plan)->keyBy('category');
        $this->assertFalse($byCat->has('STOCK'));
        $this->assertSame(['raf@example.test'], $byCat['RETARD']['recipients']);
        $this->assertSame('SIMULATION', $byCat['RETARD']['status']);
        // L'alerte reste dans l'application.
        $this->assertTrue(collect(\App\Support\AlertEngine::logistics($this->prise->id, null))->contains(fn ($al) => $al['category'] === 'STOCK' && $al['itemId'] === $a->id));
        $this->assertSame(0, DB::table('alert_dispatches')->count());

        // Envoi réel sans SMTP configuré : échec tracé, la séquence n'est pas consommée.
        $this->as($super)->postJson('/api/alert-settings/run', ['dry_run' => false])->assertOk();
        $this->assertSame(0, DB::table('alert_dispatches')->where('status', 'ENVOYE')->count());
        $this->assertGreaterThan(0, DB::table('alert_dispatches')->where('status', 'ECHEC')->count());

        // Une alerte déjà envoyée aujourd'hui n'est pas relancée avant l'intervalle.
        DB::table('alert_dispatches')->update(['status' => 'ENVOYE']);
        $this->assertSame([], array_values(array_filter($this->as($super)->postJson('/api/alert-settings/run', ['dry_run' => true])->json('plan'), fn ($m) => $m['status'] !== 'SANS_ADRESSE')));

        $id = DB::table('alert_recipients')->where('email', 'raf@example.test')->value('id');
        $this->as($super)->putJson("/api/alert-settings/recipients/{$id}/toggle")->assertOk();
        $this->as($super)->deleteJson("/api/alert-settings/recipients/{$id}")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['entity_name' => 'AlertRecipient', 'action' => 'SUPPRESSION']);
    }
}
