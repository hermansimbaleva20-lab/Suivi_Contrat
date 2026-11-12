<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Décision du 10/11/2026 : montant prévu par site et coût de l'installation de chantier ; le montant du contrat en est la
 * somme, et l'avancement physique est la somme des taux pondérés par ces montants.
 */
class WeightedProgressApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private int $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $this->company = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'GENERTEL GROUPE', 'created_at' => now(), 'updated_at' => now()]);
        $u = new User();
        $u->forceFill([
            'project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false, 'can_manage_contracts' => true, 'can_manage_progress' => true, 'can_view_reports' => true,
        ])->save();
        $this->actingAs($u, 'sanctum');
    }

    private function api()
    {
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    private function form(array $extra = []): array
    {
        return array_merge([
            'reference' => '144/PRISE/2026', 'company_id' => $this->company, 'category' => 'TRAVAUX', 'province' => 'Kasaï-Central', 'start_date' => '2026-01-15',
            'duration_months' => 12, 'initial_financing_rate' => '20', 'work_mode' => 'Lourd', 'guarantee_rate' => '5', 'guarantee_bank_name' => 'Rawbank',
            'installation_cost' => '10 000,00',
            'sites' => [['name' => 'Forage Kananga', 'amount' => '60000'], ['name' => 'Forage Tshikapa', 'amount' => '30000,00']],
        ], $extra);
    }

    private function situation(int $contract, $target, string $progress, string $date = '2026-03-01')
    {
        return $this->api()->postJson('/api/progress', ['contract_id' => $contract, 'site_id' => $target, 'date' => $date, 'progress' => $progress]);
    }

    public function test_montant_calcule_et_avancement_pondere(): void
    {
        // Le montant saisi est ignoré : c'est l'installation plus la somme des sites.
        $id = $this->api()->postJson('/api/contracts', $this->form(['amount' => '1']))->assertCreated()->json('id');
        $c = DB::table('contracts')->where('id', $id)->first();
        $this->assertEquals([100000, 10000], [(float) $c->amount, (float) $c->installation_cost]);
        $this->assertEquals(5000, DB::table('performance_guarantees')->where('contract_id', $id)->value('amount'));
        [$kananga, $tshikapa] = DB::table('contract_sites')->where('contract_id', $id)->orderBy('id')->pluck('id')->all();

        // Installation à 100 %, Kananga à 50 % (30 + 20), Tshikapa à 20 % : 10 + 30 + 6 = 46 %.
        $this->situation($id, 'INSTALLATION', '100')->assertCreated();
        $this->situation($id, $kananga, '30')->assertCreated();
        $this->situation($id, $kananga, '20')->assertCreated();
        $this->situation($id, $tshikapa, '20')->assertCreated()->assertJsonPath('progress', 46);
        $this->situation($id, 'INSTALLATION', '1')->assertStatus(422)->assertJsonPath('message', 'L’installation de chantier est déjà terminée à 100 %.');
        $this->assertTrue((bool) DB::table('progress_entries')->where('contract_id', $id)->whereNull('contract_site_id')->value('is_installation'));

        $d = $this->api()->getJson("/api/progress?contract_id={$id}")->assertOk()->json();
        $this->assertEquals(46, $d['selected']['progress']);
        $this->assertTrue($d['selected']['progressWeighted']);
        $this->assertEquals(100000, $d['breakdown']['base']);
        $this->assertEquals([10, 60, 30], array_column($d['breakdown']['rows'], 'weight'));
        $this->assertEquals([10, 30, 6], array_column($d['breakdown']['rows'], 'contribution'));
        $this->assertSame(['Installation de chantier et travaux préparatoires', 100.0, 0.0], [$d['installation']['name'], (float) $d['installation']['progress'], (float) $d['installation']['remaining']]);
        $this->assertEquals([60000, 30000], array_column($d['sites'], 'amount'));
        $this->assertSame('Installation de chantier et travaux préparatoires', collect($d['entries'])->firstWhere('installation', true)['site']);
        // Le même avancement partout : fiche du contrat et liste.
        $this->assertEquals(46, $this->api()->getJson("/api/contracts/{$id}")->json('contract.progress'));

        // Correction d'une situation de l'installation : contrôlée sur l'installation seule.
        $entry = DB::table('progress_entries')->where('contract_id', $id)->where('is_installation', true)->value('id');
        $this->api()->putJson("/api/progress/{$entry}", ['date' => '2026-03-01', 'progress' => '50'])->assertOk();
        $this->assertEquals(41, $this->api()->getJson("/api/contracts/{$id}")->json('contract.progress'));

        // Modification : un site ajouté sans montant est refusé ; avec montant, le contrat est recalculé.
        $sites = [['id' => $kananga, 'name' => 'Forage Kananga', 'amount' => '60000'], ['id' => $tshikapa, 'name' => 'Forage Tshikapa', 'amount' => '30000'], ['name' => 'Forage Mweka']];
        $this->api()->putJson("/api/contracts/{$id}", $this->form(['sites' => $sites]))->assertStatus(422)->assertJsonValidationErrors('sites');
        $sites[2]['amount'] = '100000';
        $this->api()->putJson("/api/contracts/{$id}", $this->form(['sites' => $sites]))->assertOk();
        $this->assertEquals(200000, DB::table('contracts')->where('id', $id)->value('amount'));
        $this->assertEquals(10000, DB::table('performance_guarantees')->where('contract_id', $id)->value('amount'));
        // 50 % × 5 % + 50 % × 30 % + 20 % × 15 % + 0 % × 50 % = 2,5 + 15 + 3 = 20,5 %.
        $this->assertEquals(20.5, $this->api()->getJson("/api/contracts/{$id}")->json('contract.progress'));
    }

    public function test_installation_saisie_avec_le_site_dans_la_meme_situation(): void
    {
        $id = $this->api()->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $kananga = DB::table('contract_sites')->where('contract_id', $id)->orderBy('id')->value('id');
        $post = fn (array $extra) => $this->api()->postJson('/api/progress', $extra + ['contract_id' => $id, 'date' => '2026-03-01']);

        // Site et installation ensemble : deux situations à la même date ; 60 % × 10 % + 50 % × 60 % = 36 %.
        $r = $post(['site_id' => $kananga, 'progress' => '50', 'installation_progress' => '60', 'observation' => 'RAS'])->assertCreated()->assertJsonPath('progress', 36);
        $this->assertCount(2, $r->json('ids'));
        $rows = DB::table('progress_entries')->where('contract_id', $id)->orderBy('id')->get();
        $this->assertSame([1, 0], $rows->pluck('is_installation')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['', 'RAS'], $rows->pluck('observation')->map(fn ($v) => (string) $v)->all());

        // L'installation est cumulative et plafonnée : il reste 40 %.
        $post(['installation_progress' => '50'])->assertStatus(422)->assertJsonPath('message', 'Il reste 40 % à réaliser sur l’installation de chantier.');
        // L'installation seule, sans site : 100 % × 10 % + 30 % = 40 %.
        $post(['installation_progress' => '40'])->assertCreated()->assertJsonPath('progress', 40);
        $this->assertEquals(100, $this->api()->getJson("/api/progress?contract_id={$id}")->json('installation.progress'));
        // Rien à enregistrer, ou un site sans taux : refusé.
        $post([])->assertStatus(422);
        $post(['site_id' => $kananga, 'installation_progress' => '0'])->assertStatus(422);
    }

    public function test_etat_d_execution(): void
    {
        $component = 'Réalisation de 22 systèmes d’AEP en milieu rural';
        $cid = DB::table('project_components')->insertGetId(['project_id' => $this->prise->id, 'name' => $component, 'budget' => 12010331, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->api()->postJson('/api/contracts', $this->form(['activities' => 'Construction de deux forages', 'component_id' => $cid]))->assertCreated()->json('id');
        $this->api()->postJson('/api/contracts', $this->form(['reference' => '069/PRISE/2026', 'component_id' => $cid]))->assertCreated();
        $kananga = DB::table('contract_sites')->where('contract_id', $id)->orderBy('id')->value('id');
        $this->api()->postJson('/api/progress', ['contract_id' => $id, 'site_id' => $kananga, 'date' => '2026-03-01', 'progress' => '100', 'installation_progress' => '100', 'realization_physical' => 'Forage productif', 'es_performance' => 'Excellent'])->assertStatus(422);
        $this->api()->postJson('/api/progress', ['contract_id' => $id, 'site_id' => $kananga, 'date' => '2026-03-01', 'progress' => '100', 'installation_progress' => '100', 'realization_physical' => 'Forage productif', 'es_performance' => 'Bon'])->assertCreated();
        $this->assertSame([$component], array_column($this->api()->getJson('/api/reference-data')->json('components'), 'name'));

        $m = $this->api()->getJson('/api/reports/execution?date=2026-08-31')->assertOk()->json();
        $this->assertSame(['2026-08-31', 'PRISE', '31 août 2026'], [$m['asOf'], $m['project'], $m['longDate']]);
        $row = collect($m['categories'][0]['provinces'][0]['rows'])->firstWhere('id', $id);
        $this->assertSame(['Catégorie Travaux', 'Kasaï-Central'], [$m['categories'][0]['label'], $m['categories'][0]['provinces'][0]['province']]);
        // Installation 100 % × 10 % + Kananga 100 % × 60 % = 70 %.
        $this->assertEquals([70, 100], [$row['progress'], $row['installation']['progress']]);
        $this->assertSame('Forage productif', $row['sites'][0]['situation']);
        $this->assertSame('Bon', $row['esPerformance']);
        $this->assertSame([['type' => 'Système d’AEP', 'count' => 1]], $m['completed']);
        // Une composante couvre ses deux contrats (3 lignes chacun : le contrat et ses 2 sites), avec le budget renseigné.
        $group = $m['categories'][0]['provinces'][0]['components'][0];
        $this->assertSame([$component, 12010331.0, 6], [$group['name'], (float) $group['budget'], $group['lines']]);
        $this->assertCount(2, $group['contractIds']);
        // Avant le démarrage du contrat, il n'apparaît pas.
        $this->assertSame([], $this->api()->getJson('/api/reports/execution?date=2025-12-31')->json('categories'));

        $this->api()->get('/api/reports/execution/export?date=2026-08-31')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_installation_reservee_aux_contrats_de_travaux(): void
    {
        // Contrat de SERVICE : le coût d'installation envoyé est ignoré ; montant = somme des sites.
        $id = $this->api()->postJson('/api/contracts', $this->form(['category' => 'SERVICE', 'selected_provinces' => ['Kasaï-Central'], 'guarantee_bank_name' => '',
            'sites' => [['name' => 'Bureau', 'province' => 'Kasaï-Central', 'amount' => '24000']]]))->assertCreated()->json('id');
        $c = DB::table('contracts')->where('id', $id)->first();
        $this->assertEquals([24000, null], [(float) $c->amount, $c->installation_cost]);
        $this->situation($id, 'INSTALLATION', '10')->assertStatus(422)->assertJsonPath('message', 'L’installation de chantier et les travaux préparatoires ne concernent que les contrats de TRAVAUX.');
        $d = $this->api()->getJson("/api/progress?contract_id={$id}")->assertOk()->json();
        $this->assertNull($d['installation']);
        $this->assertSame(['SITE'], array_column($d['breakdown']['rows'], 'kind'));
        $this->assertFalse($d['selected']['hasInstallation']);
    }

    public function test_saisies_refusees(): void
    {
        $this->api()->postJson('/api/contracts', $this->form(['sites' => [['name' => 'Forage Kananga']]]))->assertStatus(422)->assertJsonValidationErrors('sites');
        $this->api()->postJson('/api/contracts', $this->form(['sites' => [['name' => 'Forage Kananga', 'amount' => 'abc']]]))->assertStatus(422)->assertJsonValidationErrors('sites');
        $this->api()->postJson('/api/contracts', $this->form(['installation_cost' => '-5']))->assertStatus(422)->assertJsonValidationErrors('installation_cost');

        // L'installation concerne tout le contrat : son taux se renseigne toujours ; sans coût, son terme dans la formule vaut 0.
        $id = $this->api()->postJson('/api/contracts', $this->form(['installation_cost' => '']))->assertCreated()->json('id');
        $this->assertEquals(90000, DB::table('contracts')->where('id', $id)->value('amount'));
        $this->situation($id, 'INSTALLATION', '10')->assertCreated()->assertJsonPath('progress', 0);
        $this->assertEquals(10, $this->api()->getJson("/api/progress?contract_id={$id}")->json('installation.progress'));
    }

    public function test_ancien_contrat_sans_montant_par_site(): void
    {
        // Contrat enregistré avant la décision : sites sans montant, montant saisi, moyenne simple des sites.
        $id = DB::table('contracts')->insertGetId(['project_id' => $this->prise->id, 'phase_id' => DB::table('project_phases')->value('id'), 'company_id' => $this->company, 'reference' => 'ANCIEN',
            'category' => 'TRAVAUX', 'province' => 'Kasaï-Central', 'amount' => 80000, 'start_date' => '2026-01-01', 'end_date' => '2027-01-01', 'duration_months' => 12, 'created_at' => now(), 'updated_at' => now()]);
        $a = DB::table('contract_sites')->insertGetId(['contract_id' => $id, 'province' => 'Kasaï-Central', 'name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $b = DB::table('contract_sites')->insertGetId(['contract_id' => $id, 'province' => 'Kasaï-Central', 'name' => 'B', 'created_at' => now(), 'updated_at' => now()]);
        $this->situation($id, $a, '100')->assertCreated();
        // Le taux de l'installation se renseigne aussi sur un ancien contrat ; il comptera dès que les montants seront saisis.
        $this->situation($id, 'INSTALLATION', '10')->assertCreated();
        $this->assertEquals(50, $this->api()->getJson("/api/contracts/{$id}")->json('contract.progress'));
        $this->assertFalse($this->api()->getJson("/api/contracts/{$id}")->json('contract.progressWeighted'));

        // Modifié sans montant par site : le montant saisi reste la référence.
        $legacy = ['reference' => 'ANCIEN', 'company_id' => $this->company, 'category' => 'TRAVAUX', 'province' => 'Kasaï-Central', 'start_date' => '2026-01-01', 'duration_months' => 12,
            'amount' => '85000', 'installation_cost' => '', 'sites' => [['id' => $a, 'name' => 'A', 'amount' => ''], ['id' => $b, 'name' => 'B', 'amount' => '']]];
        $this->api()->putJson("/api/contracts/{$id}", $legacy)->assertOk();
        $this->assertEquals(85000, DB::table('contracts')->where('id', $id)->value('amount'));

        // Les montants renseignés, il passe en avancement pondéré : A (100 %) pèse 75 %.
        $legacy['sites'] = [['id' => $a, 'name' => 'A', 'amount' => '75000'], ['id' => $b, 'name' => 'B', 'amount' => '25000']];
        $this->api()->putJson("/api/contracts/{$id}", $legacy)->assertOk();
        $this->assertEquals(100000, DB::table('contracts')->where('id', $id)->value('amount'));
        $this->assertEquals(75, $this->api()->getJson("/api/contracts/{$id}")->json('contract.progress'));
    }
}
