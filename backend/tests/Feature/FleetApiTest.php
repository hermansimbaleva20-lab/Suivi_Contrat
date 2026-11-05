<?php

namespace Tests\Feature;

use App\Models\OrganizationalUnit;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Charroi et groupes (TDR §7, §8) : rôles, isolation par unité, carnet de bord, autorisation ou objection, alertes, rapport. */
class FleetApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private OrganizationalUnit $cn;
    private OrganizationalUnit $kananga;
    private OrganizationalUnit $mbujiMayi;
    private User $ca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $unit = fn (string $type, string $code) => OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => $type, 'code' => $code, 'name' => "Unité $code", 'is_active' => true]);
        $this->cn = $unit('COORDINATION', 'CN');
        $this->kananga = $unit('POOL', 'KANANGA');
        $this->mbujiMayi = $unit('POOL', 'MBUJI-MAYI');
        // Chaque unité gère sa logistique : dans une antenne, le C.A autorise les demandes.
        $this->ca = $this->user(['full_name' => 'C.A Kananga', 'can_authorize_fleet' => true, 'organizational_unit_id' => $this->kananga->id]);
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

    private function logisticien(OrganizationalUnit $unit): User
    {
        return $this->user(['can_manage_fleet' => true, 'organizational_unit_id' => $unit->id]);
    }

    private function as(User $u): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    private function vehicle(OrganizationalUnit $unit, array $attrs = []): int
    {
        return DB::table('fleet_assets')->insertGetId(array_merge([
            'project_id' => $this->prise->id, 'organizational_unit_id' => $unit->id, 'kind' => 'VEHICULE', 'name' => 'Fortuner ' . uniqid(), 'registration' => '1083 IT 318',
            'tank_capacity' => 120, 'consumption_rate' => 0.2, 'fuel_price' => 1.37, 'fuel_alert_level' => 30, 'maintenance_interval' => 3000, 'maintenance_warning' => 2500,
            'initial_meter' => 42700, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** Course adressée par défaut au C.A de Kananga (les engins des tests sont surtout dans cette antenne). */
    private function trip(User $u, int $asset, $start, $end, array $extra = [])
    {
        return $this->as($u)->postJson('/api/fleet/logs', array_merge([
            'fleet_asset_id' => $asset, 'log_date' => '2026-10-01', 'requester' => 'RAF', 'destination' => 'Bureau', 'purpose' => 'Dépôt courrier',
            'meter_start' => $start, 'meter_end' => $end, 'assigned_to' => $this->ca->id,
        ], $extra));
    }

    public function test_les_roles_ouvrent_les_bonnes_actions(): void
    {
        $this->as($this->user())->getJson('/api/fleet')->assertForbidden();
        $lecteur = $this->user(['can_view_fleet' => true, 'organizational_unit_id' => $this->kananga->id]);
        $this->as($lecteur)->getJson('/api/fleet')->assertOk();
        $v = $this->vehicle($this->kananga);
        $this->trip($lecteur, $v, 42700, 42727)->assertForbidden();

        // Chaque unité tient son propre parc ; elle n'enregistre pas d'engin dans une autre unité.
        $asset = ['kind' => 'VEHICULE', 'name' => 'Land Cruiser', 'registration' => '0001 AB 01', 'organizational_unit_id' => $this->kananga->id,
            'tank_capacity' => '120', 'consumption_rate' => '0,2', 'fuel_price' => '1,37', 'fuel_alert_level' => '30', 'maintenance_interval' => 3000, 'maintenance_warning' => 2500, 'initial_meter' => '15000'];
        $kga = $this->logisticien($this->kananga);
        $this->as($kga)->postJson('/api/fleet/assets', $asset)->assertOk();
        $this->as($this->logisticien($this->cn))->postJson('/api/fleet/assets', ['name' => 'Hilux'] + $asset)->assertForbidden();
        $this->as($kga)->postJson('/api/fleet/assets', ['name' => 'Hilux', 'organizational_unit_id' => $this->mbujiMayi->id] + $asset)->assertForbidden();
        $this->as($kga)->postJson('/api/fleet/assets', ['name' => 'Autre', 'maintenance_warning' => 3500] + $asset)->assertStatus(422)->assertJsonValidationErrors('maintenance_warning');
        $this->as($kga)->postJson('/api/fleet/assets', ['name' => 'Autre', 'fuel_alert_level' => '150'] + $asset)->assertStatus(422)->assertJsonValidationErrors('fuel_alert_level');
    }

    public function test_carnet_de_bord_calculs_et_controle_du_compteur(): void
    {
        $agent = $this->logisticien($this->kananga);
        $v = $this->vehicle($this->kananga);

        $this->trip($agent, $v, 42723, 42750, ['fuel_level_before' => '60', 'fuel_purchased' => '20', 'fuel_price' => '1,40', 'lubricant' => '1 l', 'departure_time' => '12:15', 'arrival_time' => '12:37'])->assertOk();
        $this->trip($agent, $v, 42750, 42787, ['log_date' => '2026-10-02'])->assertOk();
        // Le compteur ne recule pas ; un départ inférieur à l'arrivée précédente est refusé.
        $this->trip($agent, $v, 42780, 42800, ['log_date' => '2026-10-03'])->assertStatus(422)->assertJsonValidationErrors('meter_start');
        $this->trip($agent, $v, 42800, 42790, ['log_date' => '2026-10-03'])->assertStatus(422)->assertJsonValidationErrors('meter_end');
        $this->trip($agent, $v, 42800, 42810, ['log_date' => '2026-10-03', 'destination' => ''])->assertStatus(422)->assertJsonValidationErrors('destination');
        $this->trip($agent, $v, 42800, 42810, ['log_date' => '2026-10-03', 'fuel_level_before' => '130'])->assertStatus(422)->assertJsonValidationErrors('fuel_level_before');
        $this->trip($agent, $v, 42800, 42810, ['departure_time' => '25:00', 'log_date' => '2026-10-03'])->assertStatus(422)->assertJsonValidationErrors('departure_time');
        // La saisie est toujours adressée à un valideur habilité.
        $this->trip($agent, $v, 42800, 42810, ['log_date' => '2026-10-03', 'assigned_to' => null])->assertStatus(422)->assertJsonValidationErrors('assigned_to');
        $this->trip($agent, $v, 42800, 42810, ['log_date' => '2026-10-03', 'assigned_to' => $agent->id])->assertStatus(422)->assertJsonValidationErrors('assigned_to');

        $book = $this->as($agent)->getJson("/api/fleet/assets/{$v}/logbook?month=2026-10")->assertOk()->json();
        $this->assertCount(2, $book['lines']);
        $l = $book['lines'][0];
        $this->assertEquals(27, $l['distance']);
        $this->assertEquals(5.4, $l['estimatedLitres']);
        $this->assertEquals(28, $l['purchaseCost']); // 20 L × 1,40
        $this->assertEquals(74.6, $l['levelAfter']); // 60 + 20 − 5,4
        $this->assertEquals(67.2, $book['lines'][1]['levelAfter']); // − 37 × 0,2
        // Lubrifiant en texte : la quantité saisie, sinon « PLEIN ».
        $this->assertSame('1 L', $l['lubricant']);
        $this->assertSame('PLEIN', $book['lines'][1]['lubricant']);
        $this->assertSame('C.A Kananga', $l['assignedToName']);
        $this->assertSame([$this->ca->id], array_column($book['authorizers'], 'id'));
        // Tant que le valideur n'a pas autorisé, les courses restent hors totaux.
        $this->assertEquals(0, $book['totals']['distance']);
        $this->assertEquals(64, $book['totals']['pendingDistance']);
        foreach ($book['lines'] as $line) $this->as($this->ca)->postJson("/api/fleet/logs/{$line['id']}/authorize")->assertOk();
        $book = $this->as($agent)->getJson("/api/fleet/assets/{$v}/logbook?month=2026-10")->assertOk()->json();
        $this->assertEquals(0, $book['totals']['pending']);
        $this->assertEquals(64, $book['totals']['distance']);
        $this->assertEquals(20, $book['totals']['litresPurchased']);
        $this->assertEquals(2, $book['totals']['lubricantEntries']);
        $this->assertEquals(31.25, $book['totals']['per100Km']); // 20 L ÷ 64 km × 100
        $this->assertEquals(42787, $book['status']['currentMeter']);
        $this->assertTrue($book['canWrite']);
        $this->assertContains('RAF', $book['suggestions']['requesters']);

        // Un groupe électrogène n'a ni demandeur ni destination : relevé horaire.
        $g = $this->vehicle($this->kananga, ['kind' => 'GROUPE', 'name' => 'Groupe 20 kVA', 'registration' => null, 'consumption_rate' => 4, 'maintenance_interval' => 100, 'maintenance_warning' => 75, 'initial_meter' => 0]);
        $this->trip($agent, $g, 0, 12, ['requester' => null, 'destination' => null, 'fuel_purchased' => '50'])->assertOk();
    }

    public function test_chaque_unite_est_autonome_et_la_vue_nationale_est_designee(): void
    {
        $v = $this->vehicle($this->kananga);
        $this->vehicle($this->cn);
        $mbm = $this->logisticien($this->mbujiMayi);
        $this->as($mbm)->getJson("/api/fleet/assets/{$v}/logbook")->assertForbidden();
        $this->trip($mbm, $v, 42700, 42710)->assertForbidden();
        $this->assertSame([], $this->as($mbm)->getJson('/api/fleet')->json('assets'));

        // La coordination nationale ne voit plus les pools : seulement son propre parc.
        $cn = $this->logisticien($this->cn);
        $this->assertCount(1, $this->as($cn)->getJson('/api/fleet')->assertOk()->json('assets'));
        $this->as($cn)->getJson("/api/fleet/assets/{$v}/logbook")->assertForbidden();

        // Un compte désigné par le super-admin consulte tous les pools, sans y écrire.
        $vue = $this->user(['can_manage_fleet' => true, 'can_view_pools' => true, 'organizational_unit_id' => $this->cn->id]);
        $all = $this->as($vue)->getJson('/api/fleet')->assertOk()->json();
        $this->assertCount(2, $all['assets']);
        $this->assertSame([$this->cn->id], $all['writableUnitIds']);
        $this->trip($vue, $v, 42700, 42710)->assertForbidden();
        $this->assertFalse($this->as($vue)->getJson("/api/fleet/assets/{$v}/logbook")->json('canWrite'));

        // Un administrateur reste cantonné à son unité pour écrire.
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'organizational_unit_id' => $this->cn->id]);
        $this->trip($admin, $v, 42700, 42710)->assertForbidden();
    }

    public function test_autorisation_par_la_personne_designee_ou_objection(): void
    {
        $agent = $this->logisticien($this->kananga);
        $adjoint = $this->user(['full_name' => 'C.A adjoint Kananga', 'can_authorize_fleet' => true, 'organizational_unit_id' => $this->kananga->id]);
        $comptableCn = $this->user(['can_authorize_fleet' => true, 'organizational_unit_id' => $this->cn->id]);
        $v = $this->vehicle($this->kananga);
        $id = $this->trip($agent, $v, 42700, 42727, ['fuel_purchased' => '40'])->json('id');

        // La saisie arrive dans l'espace de la personne désignée.
        $this->assertSame(1, $this->as($this->ca)->getJson('/api/notifications')->assertOk()->json('unread'));
        $inbox = $this->as($this->ca)->getJson('/api/fleet/authorizations')->assertOk()->json();
        $this->assertSame([$id], array_column($inbox['pending'], 'id'));
        $this->assertSame([], $this->as($adjoint)->getJson('/api/fleet/authorizations')->json('pending'));

        $this->as($agent)->postJson("/api/fleet/logs/{$id}/authorize")->assertForbidden();
        $this->as($adjoint)->postJson("/api/fleet/logs/{$id}/authorize")->assertForbidden();
        // La demande reste dans l'unité : le comptable de la coordination ne peut pas être désigné.
        $this->as($agent)->putJson("/api/fleet/logs/{$id}/assignee", ['assigned_to' => $comptableCn->id])->assertStatus(422)->assertJsonValidationErrors('assigned_to');
        // Le C.A n'est pas disponible : la saisie est adressée à son adjoint habilité.
        $this->as($agent)->putJson("/api/fleet/logs/{$id}/assignee", ['assigned_to' => $adjoint->id])->assertOk();
        $this->assertSame(0, $this->as($this->ca)->getJson('/api/notifications')->json('unread'));
        $this->as($this->ca)->postJson("/api/fleet/logs/{$id}/authorize")->assertForbidden();
        $this->as($adjoint)->postJson("/api/fleet/logs/{$id}/authorize")->assertOk();
        $this->as($adjoint)->postJson("/api/fleet/logs/{$id}/authorize")->assertStatus(422);
        $this->assertSame(1, $this->as($agent)->getJson('/api/notifications')->json('unread'));

        // Pas d'autorisation sur sa propre saisie.
        $kgaValideur = $this->user(['can_authorize_fleet' => true, 'can_manage_fleet' => true, 'organizational_unit_id' => $this->kananga->id]);
        $own = $this->trip($kgaValideur, $v, 42727, 42740, ['log_date' => '2026-10-02'])->json('id');
        $this->as($kgaValideur)->postJson("/api/fleet/logs/{$own}/authorize")->assertStatus(422);

        // Objection motivée : la saisie sort des totaux et ne peut plus être autorisée.
        $this->as($this->ca)->postJson("/api/fleet/logs/{$own}/objection", ['reason' => 'non'])->assertStatus(422);
        $this->as($this->ca)->postJson("/api/fleet/logs/{$own}/objection", ['reason' => 'Course non prévue au programme'])->assertOk();
        $this->as($this->ca)->postJson("/api/fleet/logs/{$own}/authorize")->assertStatus(422);

        $book = $this->as($agent)->getJson("/api/fleet/assets/{$v}/logbook?month=2026-10")->json();
        $lines = collect($book['lines'])->keyBy('id');
        $this->assertTrue($lines[$id]['isAuthorized']);
        $this->assertTrue($lines[$own]['isObjected']);
        $this->assertSame('Course non prévue au programme', $lines[$own]['objectionReason']);
        $this->assertEquals(27, $book['totals']['distance']);
        $this->assertEquals(0, $book['totals']['pending']);
        $this->assertEquals(1, $book['totals']['objected']);
        $this->assertDatabaseHas('audit_logs', ['entity_name' => 'FleetLog', 'action' => 'AUTORISATION', 'entity_id' => (string) $id]);
        $this->assertDatabaseHas('audit_logs', ['entity_name' => 'FleetLog', 'action' => 'OBJECTION', 'entity_id' => (string) $own]);
    }

    public function test_annulation_motivee(): void
    {
        $agent = $this->logisticien($this->kananga);
        $v = $this->vehicle($this->kananga);
        $id = $this->trip($agent, $v, 42700, 42727)->json('id');
        $this->as($agent)->postJson("/api/fleet/logs/{$id}/cancel", ['reason' => 'non'])->assertStatus(422);
        $this->as($agent)->postJson("/api/fleet/logs/{$id}/cancel", ['reason' => 'Doublon de saisie'])->assertOk();
        // Le compteur est libéré : la course peut être ressaisie correctement.
        $this->trip($agent, $v, 42700, 42720)->assertOk();
        $this->as($this->ca)->postJson("/api/fleet/logs/{$id}/authorize")->assertStatus(422);
    }

    public function test_alertes_entretien_reservoir_et_assurance(): void
    {
        $agent = $this->logisticien($this->kananga);
        $v = $this->vehicle($this->kananga, ['initial_meter' => 40000]);
        $this->trip($agent, $v, 42600, 42700, ['log_date' => now()->toDateString(), 'fuel_level_before' => '45'])->assertOk(); // 45 − 20 = 25 L

        $codes = fn () => array_column($this->as($agent)->getJson('/api/fleet')->json('assets.0.alerts'), 'code');
        $this->assertEqualsCanonicalizing(['MAINTENANCE', 'FUEL', 'INSURANCE'], $codes());

        $this->as($agent)->postJson('/api/fleet/maintenances', ['fleet_asset_id' => $v, 'maintenance_date' => now()->toDateString(), 'meter_reading' => '42700', 'next_meter' => '45700', 'designation' => 'Vidange et filtres', 'cost' => '180'])->assertOk();
        $this->as($agent)->postJson('/api/fleet/insurances', ['fleet_asset_id' => $v, 'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString(), 'designation' => 'SONAS police 123', 'cost' => '950'])->assertOk();
        $this->as($agent)->postJson('/api/fleet/insurances', ['fleet_asset_id' => $v, 'start_date' => '2026-10-10', 'end_date' => '2026-10-01', 'designation' => 'X'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->assertSame(['FUEL'], $codes());

        $m = $this->as($agent)->getJson('/api/fleet/maintenances')->assertOk()->json();
        $this->assertSame(45700.0, (float) $m['assets'][0]['maintenance']['nextMeter']);
        $this->as($this->logisticien($this->mbujiMayi))->deleteJson('/api/fleet/maintenances/' . $m['history'][0]['id'], ['reason' => 'Erreur de saisie'])->assertForbidden();
    }

    public function test_programmation_du_prochain_entretien_et_fin_de_l_alerte(): void
    {
        $agent = $this->logisticien($this->kananga);
        $v = $this->vehicle($this->kananga, ['initial_meter' => 40000]);
        $this->trip($agent, $v, 42000, 42600, ['log_date' => now()->toDateString(), 'fuel_level_before' => '120'])->assertOk();
        $maintenance = fn () => $this->as($agent)->getJson('/api/fleet/maintenances')->assertOk()->json('assets.0.maintenance');
        $alert = fn () => collect($this->as($agent)->getJson('/api/fleet')->json('assets.0.alerts'))->firstWhere('code', 'MAINTENANCE');

        // Seules la logistique de l'unité programme, et le prochain doit être au-delà du dernier.
        $plan = ['last_meter' => '42000', 'next_meter' => '46000'];
        $this->as($this->logisticien($this->mbujiMayi))->putJson("/api/fleet/assets/{$v}/maintenance-plan", $plan)->assertForbidden();
        $this->as($agent)->putJson("/api/fleet/assets/{$v}/maintenance-plan", ['last_meter' => '42000', 'next_meter' => '42000'])->assertStatus(422)->assertJsonValidationErrors('next_meter');
        $this->as($agent)->putJson("/api/fleet/assets/{$v}/maintenance-plan", ['next_meter' => '46000'])->assertStatus(422)->assertJsonValidationErrors('last_meter');

        // 600 km sur 4 000 : aucune alerte.
        $this->as($agent)->putJson("/api/fleet/assets/{$v}/maintenance-plan", $plan)->assertOk();
        $m = $maintenance();
        $this->assertSame(['OK', 'PLAN', 42000.0, 46000.0, 45000.0], [$m['level'], $m['source'], (float) $m['lastMeter'], (float) $m['nextMeter'], (float) $m['warnMeter']]);
        $this->assertNull($alert());

        // Trois quarts du parcours : l'alerte commence, et se met à jour à chaque course.
        $this->trip($agent, $v, 42600, 45000, ['log_date' => now()->toDateString()])->assertOk();
        $this->assertSame('SOON', $maintenance()['level']);
        $this->assertStringContainsString('il reste 1 000 km', $alert()['text']);
        $this->assertSame('warning', $alert()['level']);
        $this->trip($agent, $v, 45000, 45800, ['log_date' => now()->toDateString()])->assertOk();
        $this->assertStringContainsString('il reste 200 km', $alert()['text']);
        // Elle figure aussi dans les alertes logistiques de l'application.
        $this->assertTrue(collect(\App\Support\AlertEngine::logistics($this->prise->id, null))->contains(fn ($a) => $a['code'] === 'MAINTENANCE' && $a['itemId'] === $v));

        // L'entretien effectué exige le prochain compteur et arrête l'alerte.
        $entry = ['fleet_asset_id' => $v, 'maintenance_date' => now()->toDateString(), 'meter_reading' => '45800', 'designation' => 'Vidange et filtres'];
        $this->as($agent)->postJson('/api/fleet/maintenances', $entry)->assertStatus(422)->assertJsonValidationErrors('next_meter');
        $this->as($agent)->postJson('/api/fleet/maintenances', $entry + ['next_meter' => '45800'])->assertStatus(422)->assertJsonValidationErrors('next_meter');
        $id = $this->as($agent)->postJson('/api/fleet/maintenances', $entry + ['next_meter' => '48800'])->assertOk()->json('id');
        $m = $maintenance();
        $this->assertSame(['OK', 'RECORD', 45800.0, 48800.0], [$m['level'], $m['source'], (float) $m['lastMeter'], (float) $m['nextMeter']]);
        $this->assertNull($alert());
        $this->assertNull(DB::table('fleet_assets')->where('id', $v)->value('maintenance_next_meter'));
        $this->assertEquals(48800, $this->as($agent)->getJson('/api/fleet/maintenances')->json('history.0.nextMeter'));

        // L'entretien corrigé : le prochain suit.
        $this->as($agent)->putJson("/api/fleet/maintenances/{$id}", $entry + ['next_meter' => '46000'])->assertOk();
        $this->assertSame([46000.0, 45950.0, 'OK'], [(float) $maintenance()['nextMeter'], (float) $maintenance()['warnMeter'], $maintenance()['level']]);
    }

    public function test_rapport_de_periode_par_mois_par_unite_et_export(): void
    {
        $agent = $this->logisticien($this->kananga);
        $v = $this->vehicle($this->kananga);
        $this->trip($agent, $v, 42700, 43000, ['log_date' => '2026-09-15', 'fuel_purchased' => '60'])->assertOk();
        $last = $this->trip($agent, $v, 43000, 43247, ['log_date' => '2026-10-02', 'fuel_purchased' => '49,4'])->assertOk()->json('id');
        foreach (DB::table('fleet_logs')->pluck('id') as $id) $this->as($this->ca)->postJson("/api/fleet/logs/{$id}/authorize")->assertOk();
        $this->trip($agent, $v, 43247, 43300, ['log_date' => '2026-10-03', 'fuel_purchased' => '10'])->assertOk(); // non autorisée : hors totaux
        $this->assertGreaterThan(0, $last);

        $vue = $this->user(['can_view_fleet' => true, 'can_view_pools' => true, 'organizational_unit_id' => $this->cn->id]);
        $rep = $this->as($vue)->getJson('/api/fleet/report?from=2026-09-01&to=2026-10-31')->assertOk()->json();
        $this->assertCount(2, $rep['assets'][0]['months']);
        $this->assertEquals(547, $rep['assets'][0]['total']['distance']);
        $this->assertEquals(109.4, $rep['grand']['litresPurchased']);
        $this->assertEquals(20, $rep['grand']['per100Km']);
        $this->assertEquals(1, $rep['grand']['pending']);
        $this->assertEquals(53, $rep['grand']['pendingDistance']);
        $this->assertSame('Unité KANANGA', $rep['unitTotals'][0]['unitName']);

        $sept = $this->as($vue)->getJson('/api/fleet/report?from=2026-09-01&to=2026-09-30')->json();
        $this->assertEquals(300, $sept['grand']['distance']);

        $res = $this->as($vue)->get('/api/fleet/report/export?from=2026-09-01&to=2026-10-31');
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', $res->getContent());
    }
}
