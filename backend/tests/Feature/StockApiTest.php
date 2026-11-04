<?php

namespace Tests\Feature;

use App\Models\OrganizationalUnit;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\StockArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Stocks (TDR §6) : habilitations, isolation projet/pool, contrôle du stock disponible, annulation tracée. */
class StockApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private Project $predire;
    private OrganizationalUnit $cn;
    private OrganizationalUnit $mbujiMayi;
    private OrganizationalUnit $kananga;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        $this->predire = Project::create(['code' => 'PREDIRE', 'name' => 'PREDIRE']);
        foreach ([$this->prise, $this->predire] as $p) {
            ProjectPhase::create(['project_id' => $p->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        }
        $unit = fn (string $type, string $code) => OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => $type, 'code' => $code, 'name' => "Unité $code", 'is_active' => true]);
        $this->cn = $unit('COORDINATION', 'CN');
        $this->mbujiMayi = $unit('POOL', 'MBUJI-MAYI');
        $this->kananga = $unit('POOL', 'KANANGA');
    }

    private function user(array $attrs = [], ?Project $p = null): User
    {
        $u = new User();
        $u->forceFill(array_merge([
            'project_id' => ($p ?? $this->prise)->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false,
        ], $attrs))->save();
        return $u;
    }

    private function admin(): User
    {
        return $this->user(['role' => 'ADMINISTRATEUR', 'is_super_admin' => true, 'organizational_unit_id' => $this->cn->id]);
    }

    private function logisticien(OrganizationalUnit $unit): User
    {
        return $this->user(['can_manage_stock' => true, 'organizational_unit_id' => $unit->id]);
    }

    private function as(User $u, string $project = 'PRISE'): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => $project, 'Accept' => 'application/json']);
    }

    private function article(string $designation = 'Rame papier duplicateur A4', float $price = 6.5): StockArticle
    {
        $n = (int) StockArticle::where('project_id', $this->prise->id)->max('number') + 1;
        return StockArticle::create(['project_id' => $this->prise->id, 'number' => $n, 'designation' => $designation, 'unit' => 'Rame', 'unit_price' => $price, 'is_active' => true]);
    }

    private function move(User $u, StockArticle $a, OrganizationalUnit $unit, string $type, $qty, string $date = '2026-10-01', string $who = 'Approvisionnement')
    {
        return $this->as($u)->postJson('/api/stock/movements', [
            'stock_article_id' => $a->id, 'organizational_unit_id' => $unit->id, 'movement_date' => $date, 'type' => $type, 'quantity' => $qty, 'origin_destination' => $who,
        ]);
    }

    public function test_le_module_est_reserve_aux_comptes_habilites(): void
    {
        $this->as($this->user())->getJson('/api/stock')->assertForbidden();
        $this->as($this->user(['can_view_stock' => true, 'organizational_unit_id' => $this->kananga->id]))->getJson('/api/stock')->assertOk();
        // Sans affectation, aucune unité n’est consultable.
        $this->as($this->user(['can_view_stock' => true]))->getJson('/api/stock')->assertForbidden();

        // Consultation seule : aucune saisie possible, même dans sa propre unité.
        $lecteur = $this->user(['can_view_stock' => true, 'organizational_unit_id' => $this->kananga->id]);
        $this->move($lecteur, $this->article(), $this->kananga, 'ENTREE', 10)->assertForbidden();
    }

    public function test_fiche_de_stock_solde_courant_et_valeur(): void
    {
        $agent = $this->logisticien($this->kananga);
        $a = $this->article();

        $this->move($agent, $a, $this->kananga, 'ENTREE', '16', '2026-10-01')->assertOk();
        $this->move($agent, $a, $this->kananga, 'SORTIE', '5', '2026-10-02', 'HNSE')->assertOk();
        $this->move($agent, $a, $this->kananga, 'SORTIE', '5,5', '2026-10-03', 'HNPM')->assertOk();

        $card = $this->as($agent)->getJson("/api/stock/articles/{$a->id}/card")->assertOk()->json();
        $this->assertSame($this->kananga->id, $card['unit']['id']);
        $this->assertEquals([16, 11, 5.5], array_column($card['lines'], 'stock'));
        $this->assertEquals(5.5, $card['stock']);
        $this->assertEquals(35.75, $card['value']); // 5,5 × 6,50 USD
        $this->assertTrue($card['canWrite']);
        $this->assertContains('HNSE', $card['origins']);

        // Période : le solde antérieur est reporté.
        $p = $this->as($agent)->getJson("/api/stock/articles/{$a->id}/card?from=2026-10-02")->json();
        $this->assertEquals(16, $p['period']['opening']);
        $this->assertCount(2, $p['lines']);
    }

    public function test_une_sortie_superieure_au_stock_est_refusee(): void
    {
        $agent = $this->logisticien($this->kananga);
        $a = $this->article();
        $this->move($agent, $a, $this->kananga, 'ENTREE', 10, '2026-10-01')->assertOk();
        $this->move($agent, $a, $this->kananga, 'SORTIE', 8, '2026-10-10', 'HNEC')->assertOk();

        $this->move($agent, $a, $this->kananga, 'SORTIE', 3, '2026-10-11', 'HNEC')->assertStatus(422)->assertJsonValidationErrors('quantity');
        // Antidatée : 10 disponibles le 5, mais la sortie du 10 deviendrait impossible.
        $this->move($agent, $a, $this->kananga, 'SORTIE', 5, '2026-10-05', 'HNEC')->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->move($agent, $a, $this->kananga, 'SORTIE', 0, '2026-10-11', 'HNEC')->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->move($agent, $a, $this->kananga, 'ENTREE', 1, '2999-01-01')->assertStatus(422)->assertJsonValidationErrors('movement_date');
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_un_agent_de_pool_ne_voit_et_ne_saisit_que_son_pool(): void
    {
        $a = $this->article();
        $kga = $this->logisticien($this->kananga);
        $mbm = $this->logisticien($this->mbujiMayi);
        $this->move($kga, $a, $this->kananga, 'ENTREE', 10)->assertOk();

        $this->move($mbm, $a, $this->kananga, 'ENTREE', 10)->assertForbidden();
        $this->as($mbm)->getJson("/api/stock/articles/{$a->id}/card?unit_id={$this->kananga->id}")->assertForbidden();

        $own = $this->as($mbm)->getJson('/api/stock?unit_id=0')->assertOk()->json();
        $this->assertSame($this->mbujiMayi->id, $own['unitId']);
        $this->assertSame([$this->mbujiMayi->id], array_column($own['units'], 'id'));
        $this->assertEquals(0, $own['articles'][0]['stock']);
        $this->assertFalse($own['canChooseUnit']);
    }

    public function test_la_vue_nationale_designee_consolide_sans_ecrire_dans_les_pools(): void
    {
        $a = $this->article();
        $this->move($this->logisticien($this->kananga), $a, $this->kananga, 'ENTREE', 10)->assertOk();
        $this->move($this->logisticien($this->mbujiMayi), $a, $this->mbujiMayi, 'ENTREE', 4)->assertOk();

        // La coordination ne voit que sa propre fiche ; la vue des pools est désignée par le super-admin.
        $this->assertSame([$this->cn->id], array_column($this->as($this->logisticien($this->cn))->getJson('/api/stock')->json('units'), 'id'));
        $cn = $this->user(['can_manage_stock' => true, 'can_view_pools' => true, 'organizational_unit_id' => $this->cn->id]);
        $all = $this->as($cn)->getJson('/api/stock')->assertOk()->json();
        $this->assertNull($all['unitId']);
        $this->assertEquals(14, $all['articles'][0]['stock']);
        $this->assertEquals(91, $all['totals']['value']);
        $this->assertSame([$this->cn->id], $all['writableUnitIds']);

        $this->move($cn, $a, $this->kananga, 'SORTIE', 1, '2026-10-02', 'HNSE')->assertForbidden();
        $this->move($cn, $a, $this->cn, 'ENTREE', 2)->assertOk();
    }

    public function test_isolation_entre_projets(): void
    {
        $a = $this->article();
        $autre = $this->user(['can_manage_stock' => true], $this->predire);
        $predireUnit = OrganizationalUnit::create(['project_id' => $this->predire->id, 'type' => 'POOL', 'code' => 'GBADOLITE', 'name' => 'Antenne GBADOLITE', 'is_active' => true]);
        $autre->forceFill(['organizational_unit_id' => $predireUnit->id])->save();
        $this->as($autre, 'PREDIRE')->getJson("/api/stock/articles/{$a->id}/card")->assertNotFound();
        $this->as($autre, 'PREDIRE')->postJson('/api/stock/movements', [
            'stock_article_id' => $a->id, 'organizational_unit_id' => $predireUnit->id, 'movement_date' => '2026-10-01', 'type' => 'ENTREE', 'quantity' => 1, 'origin_destination' => 'X',
        ])->assertStatus(422)->assertJsonValidationErrors('stock_article_id');
    }

    public function test_annulation_motivee_tracee_et_controlee(): void
    {
        $agent = $this->logisticien($this->kananga);
        $a = $this->article();
        $entree = $this->move($agent, $a, $this->kananga, 'ENTREE', 10)->json('id');
        $sortie = $this->move($agent, $a, $this->kananga, 'SORTIE', 6, '2026-10-02', 'HNSE')->json('id');

        $this->as($agent)->postJson("/api/stock/movements/{$entree}/cancel", ['reason' => 'Erreur de saisie'])->assertStatus(422);
        $this->as($this->logisticien($this->mbujiMayi))->postJson("/api/stock/movements/{$sortie}/cancel", ['reason' => 'Erreur de saisie'])->assertForbidden();
        $this->as($agent)->postJson("/api/stock/movements/{$sortie}/cancel", ['reason' => 'ok'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($agent)->postJson("/api/stock/movements/{$sortie}/cancel", ['reason' => 'Doublon de saisie'])->assertOk();
        $this->as($agent)->postJson("/api/stock/movements/{$sortie}/cancel", ['reason' => 'Doublon de saisie'])->assertStatus(422);

        $card = $this->as($agent)->getJson("/api/stock/articles/{$a->id}/card")->json();
        $this->assertEquals(10, $card['stock']);
        $this->assertTrue($card['lines'][1]['isCancelled']);
        $this->assertSame('Doublon de saisie', $card['lines'][1]['cancelReason']);

        $log = DB::table('audit_logs')->where('entity_name', 'StockMovement')->where('action', 'ANNULATION')->first();
        $this->assertNotNull($log);
        $this->assertNull(json_decode($log->old_values, true)['cancelled_at']);
        $this->assertNotNull(json_decode($log->new_values, true)['cancelled_at']);
    }

    public function test_chaque_antenne_ajoute_ses_articles_par_contrat_ou_un_par_un(): void
    {
        $kga = $this->logisticien($this->kananga);
        $mbm = $this->logisticien($this->mbujiMayi);
        $cn = $this->logisticien($this->cn);
        $commun = $this->as($cn)->postJson('/api/stock/articles', ['designation' => 'Rame papier A4', 'unit' => 'Rame', 'unit_price' => '6,5'])->assertOk()->json('id');

        // Sans contrat : article ajouté un par un, propre à l'antenne.
        $propre = $this->as($kga)->postJson('/api/stock/articles', ['designation' => 'Cadenas', 'unit' => 'Pce', 'unit_price' => '4'])->assertOk()->json('id');
        $this->assertSame($this->kananga->id, StockArticle::find($propre)->organizational_unit_id);
        $this->as($kga)->postJson('/api/stock/articles', ['designation' => 'rame papier a4', 'unit' => 'Rame', 'unit_price' => '7'])->assertStatus(422)->assertJsonValidationErrors('designation');
        // Une autre antenne peut avoir son propre « Cadenas » ; elle ne voit ni ne modifie celui de Kananga.
        $this->as($mbm)->postJson('/api/stock/articles', ['designation' => 'Cadenas', 'unit' => 'Pce', 'unit_price' => '5'])->assertOk();
        $this->assertNotContains($propre, array_column($this->as($mbm)->getJson('/api/stock')->json('articles'), 'id'));
        $this->as($mbm)->putJson("/api/stock/articles/{$propre}/toggle")->assertNotFound();
        // Le catalogue commun reste tenu par la coordination.
        $this->as($kga)->putJson("/api/stock/articles/{$commun}/toggle")->assertForbidden();
        $view = collect($this->as($kga)->getJson('/api/stock')->json('articles'))->keyBy('id');
        $this->assertFalse($view[$commun]['canEdit']);
        $this->assertTrue($view[$propre]['canEdit']);
        $this->assertSame('Unité KANANGA', $view[$propre]['ownerUnit']);
        $this->move($kga, StockArticle::find($propre), $this->kananga, 'ENTREE', 5)->assertOk();

        // Par contrat : import du fichier des articles du contrat.
        $company = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'Fournisseur', 'created_at' => now(), 'updated_at' => now()]);
        $contract = DB::table('contracts')->insertGetId(['project_id' => $this->prise->id, 'phase_id' => DB::table('project_phases')->value('id'), 'company_id' => $company, 'reference' => 'F-12',
            'category' => 'BIENS', 'amount' => 1000, 'start_date' => '2026-01-01', 'duration_months' => 6, 'created_at' => now(), 'updated_at' => now()]);
        $csv = "N°;ARTICLE;UNITE;QUANTITE;PU \$;PT \$\n;Toner HP 85A;Pce;4;45;180\n";
        $this->as($kga)->post('/api/stock/import', ['file' => UploadedFile::fake()->createWithContent('contrat.csv', $csv), 'contract_id' => $contract, 'initial_stock' => 1, 'movement_date' => '2026-10-01'])->assertOk();
        $toner = StockArticle::where('designation', 'Toner HP 85A')->first();
        $this->assertSame($contract, $toner->contract_id);
        $this->assertSame($this->kananga->id, $toner->organizational_unit_id);
        $this->assertSame('F-12', collect($this->as($kga)->getJson('/api/stock')->json('articles'))->firstWhere('id', $toner->id)['contractNumber']);
    }

    public function test_catalogue_tenu_par_la_coordination(): void
    {
        $data = ['designation' => 'Agrafeuse (grand format)', 'unit' => 'Pce', 'unit_price' => '12,30'];

        $cn = $this->logisticien($this->cn);

        $id = $this->as($cn)->postJson('/api/stock/articles', $data)->assertOk()->json('id');
        $a = StockArticle::find($id);
        $this->assertSame(1, $a->number);
        $this->assertEquals(12.3, $a->unit_price);
        $this->as($cn)->postJson('/api/stock/articles', $data)->assertStatus(422)->assertJsonValidationErrors('designation');
        $this->as($cn)->postJson('/api/stock/articles', ['designation' => 'Binder 25', 'unit' => 'Pqt', 'unit_price' => '-1'])->assertStatus(422)->assertJsonValidationErrors('unit_price');

        // Un article qui a des mouvements se désactive, il ne se supprime pas.
        $this->move($this->admin(), $a, $this->kananga, 'ENTREE', 3)->assertOk();
        $this->as($cn)->deleteJson("/api/stock/articles/{$id}")->assertStatus(422);
        $this->as($cn)->putJson("/api/stock/articles/{$id}/toggle")->assertOk();
        $this->move($this->admin(), $a->fresh(), $this->kananga, 'ENTREE', 3)->assertStatus(422);

        $vide = $this->as($cn)->postJson('/api/stock/articles', ['designation' => 'Binder 30', 'unit' => 'Pqt', 'unit_price' => '20'])->json('id');
        $this->as($cn)->deleteJson("/api/stock/articles/{$vide}")->assertOk();
    }
}
