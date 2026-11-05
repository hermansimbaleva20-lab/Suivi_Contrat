<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Journal d'audit filtrable par personne. */
class AuditApiTest extends TestCase
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
