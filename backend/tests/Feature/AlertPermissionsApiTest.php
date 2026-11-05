<?php

namespace Tests\Feature;

use App\Models\OrganizationalUnit;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\StockArticle;
use App\Models\User;
use App\Support\AlertEngine;
use App\Support\Dates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Habilitations d'alertes scindées : contrats, bonne exécution, logistique (par unité). */
class AlertPermissionsApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private ProjectPhase $phase;
    private OrganizationalUnit $cn;
    private OrganizationalUnit $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        $this->phase = ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $this->cn = OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => 'COORDINATION', 'code' => 'CN', 'name' => 'Coordination nationale', 'is_active' => true]);
        $this->pool = OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => 'POOL', 'code' => 'KGA', 'name' => 'Antenne KANANGA', 'is_active' => true]);
        $today = Dates::today();
        $co = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'SZTC', 'email' => 'contact@sztc.test', 'created_at' => now(), 'updated_at' => now()]);
        $c = DB::table('contracts')->insertGetId(['project_id' => $this->prise->id, 'phase_id' => $this->phase->id, 'company_id' => $co, 'reference' => '072', 'category' => 'TRAVAUX', 'amount' => 1000,
            'start_date' => Dates::addDays($today, -200), 'end_date' => Dates::addDays($today, -10), 'duration_months' => 6, 'created_at' => now(), 'updated_at' => now()]);
        $this->contract = $c;
        $this->guarantee = DB::table('performance_guarantees')->insertGetId(['project_id' => $this->prise->id, 'contract_id' => $c, 'company_id' => $co, 'bank_name' => 'Rawbank', 'account_number' => 'GBE-72', 'rate' => 5, 'amount' => 50,
            'start_date' => Dates::addDays($today, -200), 'end_date' => Dates::addDays($today, -10), 'created_at' => now(), 'updated_at' => now()]);
    }

    private int $contract;
    private int $guarantee;

    private function user(array $attrs): User
    {
        $u = new User();
        $u->forceFill(array_merge(['project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false, 'organizational_unit_id' => $this->cn->id], $attrs))->save();
        return $u;
    }

    private function as(User $u): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    /** Article en rupture dans une unité. */
    private function outOfStock(OrganizationalUnit $unit, string $name): int
    {
        $a = StockArticle::create(['project_id' => $this->prise->id, 'number' => random_int(1, 9999), 'designation' => $name, 'unit' => 'Pce', 'unit_price' => 10, 'alert_threshold' => 5, 'is_active' => true]);
        DB::table('stock_movements')->insert([
            ['project_id' => $this->prise->id, 'organizational_unit_id' => $unit->id, 'stock_article_id' => $a->id, 'movement_date' => '2026-10-01', 'type' => 'ENTREE', 'quantity' => 2, 'origin_destination' => 'X', 'created_at' => now(), 'updated_at' => now()],
            ['project_id' => $this->prise->id, 'organizational_unit_id' => $unit->id, 'stock_article_id' => $a->id, 'movement_date' => '2026-10-02', 'type' => 'SORTIE', 'quantity' => 2, 'origin_destination' => 'Y', 'created_at' => now(), 'updated_at' => now()],
        ]);
        return $a->id;
    }

    public function test_sans_aucune_habilitation_la_page_est_interdite(): void
    {
        $this->as($this->user([]))->getJson('/api/alerts')->assertForbidden();
    }

    public function test_alertes_des_contrats_seulement(): void
    {
        $u = $this->user(['can_view_alerts_contracts' => true, 'can_view_alert_history' => true]);
        $d = $this->as($u)->getJson('/api/alerts')->assertOk()->json();
        $this->assertContains($this->contract, array_column($d['retards'], 'id'));
        $this->assertSame([[], []], [$d['guarantees'], $d['logistics']]);

        $this->as($u)->postJson('/api/alerts/send', ['type' => 'retard', 'id' => $this->contract, 'preview' => true])->assertOk();
        $this->as($u)->postJson('/api/alerts/send', ['type' => 'garantie', 'id' => $this->guarantee, 'preview' => true])->assertForbidden();

        $codes = array_column($this->as($u)->getJson('/api/alerts/templates')->assertOk()->json('templates'), 'code');
        $this->assertContains('CONTRAT_RETARD', $codes);
        $this->assertNotContains('DECOMPTE_RAPPEL', $codes);
        $this->assertNotContains('GARANTIE_RETARD', $codes);
        $this->assertNotContains('GARANTIE_PERSONNEL', $codes);

        $s = $this->as($u)->getJson('/api/auth/me')->assertOk()->json('alerts');
        $this->assertSame([1, 0, 0], [$s['contracts'], $s['guarantees'], $s['logistics']]);
    }

    public function test_alertes_de_bonne_execution_seulement(): void
    {
        $u = $this->user(['can_view_alerts_guarantees' => true, 'can_view_alert_history' => true]);
        $d = $this->as($u)->getJson('/api/alerts')->assertOk()->json();
        $this->assertSame([$this->guarantee], array_column($d['guarantees'], 'id'));
        $this->assertSame([[], [], [], [], []], [$d['retards'], $d['vigilances'], $d['sites'], $d['contracts'], $d['logistics']]);

        $this->as($u)->postJson('/api/alerts/send', ['type' => 'garantie', 'id' => $this->guarantee, 'preview' => true])->assertOk();
        $this->as($u)->postJson('/api/alerts/send', ['type' => 'retard', 'id' => $this->contract, 'preview' => true])->assertForbidden();
        $this->assertSame(['GARANTIE'], array_values(array_unique(array_map(fn ($t) => $t['about'], $this->as($u)->getJson('/api/alerts/templates')->json('templates')))));

        // L'historique ne montre que ses messages.
        $line = fn (string $key, ?int $g) => ['project_id' => $this->prise->id, 'category' => $g ? 'GARANTIE' : 'CONTRAT', 'alert_key' => $key, 'subject' => 'x', 'recipients' => 'x', 'status' => 'ENVOYE', 'trigger' => 'AUTO', 'contract_id' => $this->contract, 'performance_guarantee_id' => $g, 'created_at' => now(), 'updated_at' => now()];
        DB::table('alert_dispatches')->insert([$line('GARANTIE:1', $this->guarantee), $line('CONTRAT:1', null)]);
        $h = $this->as($u)->getJson('/api/alerts/history')->assertOk()->json();
        $this->assertSame(['GARANTIE'], array_column($h['data'], 'scope'));
        $c = $this->user(['can_view_alerts_contracts' => true, 'can_view_alert_history' => true]);
        $h = $this->as($c)->getJson('/api/alerts/history')->assertOk()->json();
        $this->assertSame(['CONTRAT'], array_column($h['data'], 'scope'));
        $this->assertSame([], $h['guarantees']);
    }

    public function test_modeles_et_historique_reserves_au_super_admin_et_aux_comptes_habilites(): void
    {
        $paths = ['/api/alerts/templates', '/api/alerts/history'];
        $tpl = ['subject' => 'Objet {contrat}', 'body' => 'Message du contrat {contrat}, vingt caractères au moins.'];
        $me = fn ($u) => $this->as($u)->getJson('/api/auth/me')->assertOk()->json('user.permissions.canViewAlertHistory');

        // Un simple droit d'alerte ou le rôle d'administrateur ne suffit pas, ni pour lire ni pour modifier.
        foreach ([$this->user(['can_view_alerts_contracts' => true]), $this->user(['role' => 'ADMINISTRATEUR'])] as $u) {
            foreach ($paths as $path) $this->as($u)->getJson($path)->assertForbidden();
            $this->assertFalse($me($u));
        }
        $this->as($this->user(['role' => 'ADMINISTRATEUR']))->putJson('/api/alerts/templates/CONTRAT_RETARD', $tpl)->assertForbidden();

        // Le super-administrateur voit toujours, sans avoir la case.
        $super = $this->user(['role' => 'ADMINISTRATEUR', 'is_super_admin' => true]);
        foreach ($paths as $path) $this->as($super)->getJson($path)->assertOk();
        $this->assertTrue($me($super));

        // L'habilitation s'ajoute aux rubriques : seule, elle n'ouvre rien ; elle ne donne pas le droit de modifier.
        $seule = $this->user(['can_view_alert_history' => true]);
        $this->assertTrue($me($seule));
        foreach ($paths as $path) $this->as($seule)->getJson($path)->assertForbidden();
        $agent = $this->user(['can_view_alerts_contracts' => true, 'can_view_alert_history' => true]);
        $this->as($agent)->getJson('/api/alerts/templates')->assertOk()->assertJsonPath('canEdit', false);
        $this->as($agent)->putJson('/api/alerts/templates/CONTRAT_RETARD', $tpl)->assertForbidden();

        // Accordée à un administrateur de la coordination, elle lui rend aussi la modification.
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'can_view_alert_history' => true]);
        $this->as($admin)->getJson('/api/alerts/templates')->assertOk()->assertJsonPath('canEdit', true);
    }

    public function test_alertes_logistiques_seulement_et_limitees_a_l_unite(): void
    {
        $kga = $this->outOfStock($this->pool, 'Cartouche Kananga');
        $this->outOfStock($this->cn, 'Cartouche Coordination');

        $u = $this->user(['can_view_alerts_logistics' => true, 'organizational_unit_id' => $this->pool->id]);
        $d = $this->as($u)->getJson('/api/alerts')->assertOk()->json();
        $this->assertSame([[], []], [$d['retards'], $d['guarantees']]);
        $this->assertCount(1, $d['logistics']);
        $this->assertSame(['STOCK', 'danger', 'OUT', $kga, 'Cartouche Kananga', 'Antenne KANANGA'],
            [$d['logistics'][0]['category'], $d['logistics'][0]['level'], $d['logistics'][0]['code'], $d['logistics'][0]['itemId'], $d['logistics'][0]['name'], $d['logistics'][0]['unitName']]);
        $this->assertSame(1, $this->as($u)->getJson('/api/auth/me')->json('alerts.logistics'));

        // Pas d'envoi, de modèle ni d'historique pour ce seul droit.
        $this->as($u)->postJson('/api/alerts/send', ['type' => 'retard', 'id' => $this->contract, 'preview' => true])->assertForbidden();
        $this->as($u)->getJson('/api/alerts/templates')->assertForbidden();
        $this->as($u)->getJson('/api/alerts/history')->assertForbidden();

        // Vue nationale : tout le projet ; compte sans unité : rien.
        $national = $this->user(['can_view_alerts_logistics' => true, 'can_view_pools' => true]);
        $this->assertCount(2, $this->as($national)->getJson('/api/alerts')->json('logistics'));
        $none = $this->user(['can_view_alerts_logistics' => true, 'organizational_unit_id' => null]);
        $this->assertSame([], $this->as($none)->getJson('/api/alerts')->json('logistics'));
        $this->assertCount(2, AlertEngine::logistics($this->prise->id, null));
    }

    public function test_seul_le_super_admin_choisit_et_les_habilitations_sont_exposees(): void
    {
        $agent = $this->user([]);
        $super = $this->user(['is_super_admin' => true, 'role' => 'ADMINISTRATEUR']);
        $admin = $this->user(['role' => 'ADMINISTRATEUR']);
        $this->as($admin)->putJson("/api/users/{$agent->id}/permissions", ['can_view_alerts_logistics' => true])->assertForbidden();
        $this->as($super)->putJson("/api/users/{$agent->id}/permissions", ['role' => 'UTILISATEUR', 'can_view_alerts_contracts' => false, 'can_view_alerts_guarantees' => true, 'can_view_alerts_logistics' => true])->assertOk();

        $fresh = DB::table('users')->where('id', $agent->id)->first();
        $this->assertSame([0, 1, 1], [(int) $fresh->can_view_alerts_contracts, (int) $fresh->can_view_alerts_guarantees, (int) $fresh->can_view_alerts_logistics]);
        $p = $this->as(User::find($agent->id))->getJson('/api/auth/me')->assertOk()->json('user.permissions');
        $this->assertSame([false, true, true], [$p['canViewAlertsContracts'], $p['canViewAlertsGuarantees'], $p['canViewAlertsLogistics']]);
    }
}
