<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Ctx;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** IA réglée par le super-admin (appels simulés) et journal d'audit filtrable par personne. */
class AiAuditApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
    }

    private function user(array $attrs = []): User
    {
        $u = new User();
        $u->forceFill(array_merge([
            'project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent ' . uniqid(), 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false,
        ], $attrs))->save();
        return $u;
    }

    private function as(User $u): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    private function claudeReplies(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn'])]);
    }

    public function test_ia_desactivee_par_defaut_puis_reglee_par_le_super_admin(): void
    {
        $super = $this->user(['is_super_admin' => true]);
        $admin = $this->user(['role' => 'ADMINISTRATEUR']);
        $lecteur = $this->user(['can_view_reports' => true]);

        $this->as($lecteur)->postJson('/api/ai/report-comment', ['period_type' => 'TRIMESTRE', 'year' => 2026, 'index' => 3])->assertForbidden();
        $this->as($admin)->getJson('/api/ai/settings')->assertForbidden();
        $settings = ['is_enabled' => true, 'provider' => 'ANTHROPIC', 'model' => 'claude-opus-5-5', 'features' => ['comments' => true, 'alerts' => false, 'extraction' => true]];
        $this->as($super)->putJson('/api/ai/settings', $settings)->assertStatus(422); // pas de clé
        $this->as($super)->putJson('/api/ai/settings', $settings + ['api_key' => 'sk-ant-test-123'])->assertOk();
        $this->assertNotSame('sk-ant-test-123', DB::table('ai_settings')->value('api_key')); // chiffrée
        $this->assertFalse($this->as($super)->getJson('/api/ai/settings')->json('hasKey') === false);
        $this->assertSame(['comments' => true, 'alerts' => false, 'extraction' => true], $this->as($lecteur)->getJson('/api/ai/status')->json());

        $this->claudeReplies('Le trimestre montre un retard marqué sur deux contrats.');
        $c = $this->as($lecteur)->postJson('/api/ai/report-comment', ['period_type' => 'TRIMESTRE', 'year' => 2026, 'index' => 3])->assertOk()->json('comment');
        $this->assertSame('Le trimestre montre un retard marqué sur deux contrats.', $c);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('x-api-key', 'sk-ant-test-123') && $r['model'] === 'claude-opus-5-5' && $r['fallbacks'] === 'default');

        // Réglage sans nouvelle clé : la clé enregistrée est conservée.
        $this->as($super)->putJson('/api/ai/settings', ['model' => 'claude-sonnet-5-5'] + $settings)->assertOk();
        $this->assertTrue($this->as($super)->getJson('/api/ai/settings')->json('hasKey'));
        $this->assertDatabaseHas('audit_logs', ['entity_name' => 'AiSettings', 'action' => 'MODIFICATION']);
        $this->assertStringNotContainsString('sk-ant', (string) DB::table('audit_logs')->where('entity_name', 'AiSettings')->value('new_values'));
    }

    public function test_reperage_de_l_entreprise_dans_un_contrat(): void
    {
        $super = $this->user(['is_super_admin' => true]);
        $this->as($super)->putJson('/api/ai/settings', ['is_enabled' => true, 'provider' => 'ANTHROPIC', 'model' => 'claude-opus-5-5', 'api_key' => 'k',
            'features' => ['comments' => false, 'alerts' => false, 'extraction' => true]])->assertOk();
        $co = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'GENERTEL Groupe SARL', 'created_at' => now(), 'updated_at' => now()]);
        $this->claudeReplies("Voici :\n```json\n{\"entreprise\": \"Genertel Groupe\", \"numero_contrat\": \"144\", \"montant\": 10933203.25, \"emails\": [\"contact@genertel.cd\", \"pas-une-adresse\"], \"telephones\": [\"+243 81 000 00 00\"]}\n```");
        $agent = $this->user(['can_manage_contracts' => true, 'can_manage_companies' => true]);

        $r = $this->as($agent)->postJson('/api/ai/extract', ['text' => str_repeat('Contrat N°144 entre le projet PRISE et GENERTEL Groupe. ', 3)])->assertOk()->json();
        $this->assertSame($co, $r['match']['id']);
        $this->assertSame(['contact@genertel.cd'], $r['fields']['emails']);
        $this->as($agent)->postJson('/api/ai/extract/apply', ['company_id' => $co, 'email' => 'contact@genertel.cd'])->assertOk();
        $this->assertSame('contact@genertel.cd', DB::table('companies')->where('id', $co)->value('email'));
        $this->as($agent)->postJson('/api/ai/extract/apply', ['company_id' => $co, 'email' => 'autre@genertel.cd'])->assertStatus(422); // déjà renseignée
    }

    public function test_journal_filtre_par_personne_avec_activite_par_compte(): void
    {
        $admin = $this->user(['can_manage_users' => true, 'can_view_pools' => true]);
        $alice = $this->user(['full_name' => 'Alice Logisticienne']);
        $bob = $this->user(['full_name' => 'Bob Comptable']);
        foreach ([[$alice, 'CREATION', 'StockMovement'], [$alice, 'ANNULATION', 'StockMovement'], [$bob, 'VALIDATION', 'Decompte']] as [$u, $action, $entity]) {
            DB::table('audit_logs')->insert(['project_id' => $this->prise->id, 'user_id' => $u->id, 'action' => $action, 'entity_name' => $entity, 'entity_id' => '1',
                'details' => "Opération de {$u->full_name}", 'old_values' => json_encode(['status' => 'EN_ATTENTE']), 'new_values' => json_encode(['status' => 'VALIDE']), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->as($this->user())->getJson('/api/audit')->assertForbidden();
        // Hors vue nationale, on ne consulte que les opérations faites dans son unité (ici : aucune).
        $this->assertSame(0, $this->as($this->user(['can_manage_users' => true]))->getJson('/api/audit')->assertOk()->json('meta.total'));

        $users = collect($this->as($admin)->getJson('/api/audit/users')->assertOk()->json())->keyBy('user');
        $this->assertSame(2, $users['Alice Logisticienne']['count']);
        $this->assertSame(1, $users['Bob Comptable']['count']);

        $d = $this->as($admin)->getJson("/api/audit?user_id={$alice->id}")->assertOk()->json();
        $this->assertSame(2, $d['meta']['total']);
        $this->assertSame('Mouvement de stock', $d['data'][0]['entity']);
        $this->assertSame(1, $this->as($admin)->getJson('/api/audit?entity=Decompte')->json('meta.total'));
        $this->assertSame('VALIDE', $this->as($admin)->getJson('/api/audit?q=Bob')->json('data.0.after.status'));
        $this->assertStringStartsWith('PK', $this->as($admin)->get("/api/audit/export?user_id={$bob->id}")->assertOk()->getContent());
    }
}
