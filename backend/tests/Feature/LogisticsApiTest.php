<?php

namespace Tests\Feature;

use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\StockArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Logistique (28/10/2026) : livraisons du fournisseur, import du catalogue, unité implicite, postes par unité, tableau de bord. */
class LogisticsApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private OrganizationalUnit $cn;
    private OrganizationalUnit $kananga;
    private OrganizationalUnit $mbujiMayi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $unit = fn (string $type, string $code) => OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => $type, 'code' => $code, 'name' => "Unité $code", 'is_active' => true]);
        $this->cn = $unit('COORDINATION', 'CN');
        $this->kananga = $unit('POOL', 'KANANGA');
        $this->mbujiMayi = $unit('POOL', 'MBUJI-MAYI');
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

    private function article(string $name, float $price = 10): StockArticle
    {
        $n = (int) StockArticle::where('project_id', $this->prise->id)->max('number') + 1;
        return StockArticle::create(['project_id' => $this->prise->id, 'number' => $n, 'designation' => $name, 'unit' => 'Pce', 'unit_price' => $price, 'is_active' => true]);
    }

    private function company(string $name, ?Project $p = null): int
    {
        return DB::table('companies')->insertGetId(['project_id' => ($p ?? $this->prise)->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_livraison_du_fournisseur_dans_l_unite_de_l_agent_sans_la_choisir(): void
    {
        $agent = $this->user(['can_manage_stock' => true, 'organizational_unit_id' => $this->kananga->id]);
        $rame = $this->article('Rame papier A4', 6.5);
        $stylo = $this->article('Stylo bleu', 0.5);
        $fournisseur = $this->company('Bureautique Kasaï SARL');

        $this->as($agent)->postJson('/api/stock/deliveries', [
            'company_id' => $fournisseur, 'movement_date' => '2026-10-01', 'reference' => 'BL-0042',
            'lines' => [['stock_article_id' => $rame->id, 'quantity' => '16'], ['stock_article_id' => $stylo->id, 'quantity' => '2,5']],
        ])->assertOk();

        $card = $this->as($agent)->getJson("/api/stock/articles/{$rame->id}/card")->assertOk()->json();
        $this->assertSame($this->kananga->id, $card['unit']['id']);
        $this->assertSame('Livraison Bureautique Kasaï SARL', $card['lines'][0]['originDestination']);
        $this->assertSame('Bureautique Kasaï SARL', $card['lines'][0]['company']);
        $this->assertSame('BL-0042', $card['lines'][0]['reference']);
        $this->assertSame(1, $card['lines'][0]['operationNo']);

        // Sortie sans désigner l'unité : celle de l'agent ; N° d'opération suivant.
        $this->as($agent)->postJson('/api/stock/movements', ['stock_article_id' => $rame->id, 'movement_date' => '2026-10-02', 'type' => 'SORTIE', 'quantity' => 5, 'origin_destination' => 'HNSE'])->assertOk();
        $this->assertSame(2, $this->as($agent)->getJson("/api/stock/articles/{$rame->id}/card")->json('lines.1.operationNo'));

        // Tout ou rien : un article en double ou un fournisseur d'un autre projet bloquent toute la livraison.
        $this->as($agent)->postJson('/api/stock/deliveries', ['company_id' => $fournisseur, 'movement_date' => '2026-10-03',
            'lines' => [['stock_article_id' => $rame->id, 'quantity' => 1], ['stock_article_id' => $rame->id, 'quantity' => 1]]])->assertStatus(422);
        $autre = $this->company('Autre projet', Project::create(['code' => 'PREDIRE', 'name' => 'PREDIRE']));
        $this->as($agent)->postJson('/api/stock/deliveries', ['company_id' => $autre, 'movement_date' => '2026-10-03',
            'lines' => [['stock_article_id' => $rame->id, 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->assertSame(3, DB::table('stock_movements')->count());

        // Un agent de pool ne peut pas désigner une autre unité ; un compte sans unité doit être affecté.
        $this->as($agent)->postJson('/api/stock/movements', ['organizational_unit_id' => $this->mbujiMayi->id, 'stock_article_id' => $rame->id, 'movement_date' => '2026-10-02', 'type' => 'ENTREE', 'quantity' => 1, 'origin_destination' => 'X'])->assertForbidden();
        $sansUnite = $this->user(['can_manage_stock' => true]);
        $this->as($sansUnite)->postJson('/api/stock/movements', ['stock_article_id' => $rame->id, 'movement_date' => '2026-10-02', 'type' => 'ENTREE', 'quantity' => 1, 'origin_destination' => 'X'])->assertStatus(422)->assertJsonValidationErrors('organizational_unit_id');
    }

    public function test_import_du_catalogue_apercu_puis_import_avec_stock_initial(): void
    {
        $this->article('Rame papier A4', 6);
        $csv = "N°;ARTICLE;UNITE;QUANTITE;PU \$;PT \$\n;Rame papier A4;Rame;10;6,50;65\n2;Cartouche HP 85A;Pce;5;45;225\n3;Stylo bleu;Boîte/50;;12;\n";
        $file = fn () => UploadedFile::fake()->createWithContent('prix.csv', $csv);

        // Une antenne importe aussi : l'article commun déjà connu est utilisé tel quel, les nouveaux deviennent les siens.
        $kga = $this->user(['can_manage_stock' => true, 'organizational_unit_id' => $this->kananga->id]);
        $this->assertSame(['new' => 2, 'update' => 0, 'keep' => 1, 'error' => 0], $this->as($kga)->post('/api/stock/import', ['file' => $file(), 'dry_run' => 1])->assertOk()->json('counts'));

        $cn = $this->user(['can_manage_stock' => true, 'organizational_unit_id' => $this->cn->id]);
        $preview = $this->as($cn)->post('/api/stock/import', ['file' => $file(), 'dry_run' => 1])->assertOk()->json();
        $this->assertSame(['new' => 2, 'update' => 1, 'keep' => 0, 'error' => 0], $preview['counts']);

        $this->assertSame('UPDATE', $preview['rows'][0]['status']);
        $this->assertEquals(225, $preview['rows'][1]['total']);
        $this->assertSame(1, StockArticle::count());

        $this->as($cn)->post('/api/stock/import', ['file' => $file(), 'initial_stock' => 1, 'movement_date' => '2026-10-01'])->assertOk();
        $this->assertSame(3, StockArticle::count());
        $this->assertEquals(6.5, StockArticle::where('designation', 'Rame papier A4')->value('unit_price'));
        $this->assertSame(2, (int) StockArticle::where('designation', 'Cartouche HP 85A')->value('number'));
        $this->assertSame(2, DB::table('stock_movements')->where('organizational_unit_id', $this->cn->id)->where('origin_destination', 'Stock initial (import)')->count());

        // Un fichier en erreur n'importe rien.
        $bad = UploadedFile::fake()->createWithContent('bad.csv', "Article;Unité;PU\nGomme;;1\n");
        $this->as($cn)->post('/api/stock/import', ['file' => $bad])->assertStatus(422);
        $this->assertSame(3, StockArticle::count());

        // Le modèle Excel téléchargé se réimporte tel quel.
        $template = $this->as($cn)->get('/api/stock/import/template')->assertOk()->getContent();
        $xlsx = UploadedFile::fake()->createWithContent('modele.xlsx', $template);
        $rows = $this->as($cn)->post('/api/stock/import', ['file' => $xlsx, 'dry_run' => 1])->assertOk()->json('rows');
        $this->assertSame('Agrafeuse (grand format) : qualité supérieure', $rows[0]['designation']);
        $this->assertEquals(12, $rows[0]['unitPrice']);
    }

    public function test_colisage_parametrable(): void
    {
        $cn = $this->user(['can_manage_stock' => true, 'organizational_unit_id' => $this->cn->id]);
        $this->as($cn)->postJson('/api/stock/articles', ['designation' => 'Stylos', 'unit' => 'Carton', 'pack_size' => '12', 'pack_unit' => 'pièces', 'unit_price' => '6'])->assertOk();
        $this->as($cn)->postJson('/api/stock/articles', ['designation' => 'Cartons', 'unit' => 'Colis', 'pack_size' => '10', 'unit_price' => '6'])->assertStatus(422)->assertJsonValidationErrors('pack_unit');
        $a = collect($this->as($cn)->getJson('/api/stock')->json('articles'))->firstWhere('designation', 'Stylos');
        $this->assertEquals(12, $a['packSize']);
        $this->assertSame('pièces', $a['packUnit']);
    }

    public function test_postes_rattaches_a_une_unite(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'organizational_unit_id' => $this->cn->id]);
        $this->as($admin)->postJson('/api/organization/positions', ['name' => 'Logisticien', 'organizational_unit_id' => $this->kananga->id])->assertCreated();
        $this->as($admin)->postJson('/api/organization/positions', ['name' => 'Logisticien', 'organizational_unit_id' => $this->mbujiMayi->id])->assertCreated();
        $this->as($admin)->postJson('/api/organization/positions', ['name' => 'Logisticien', 'organizational_unit_id' => $this->kananga->id])->assertStatus(422)->assertJsonValidationErrors('name');
        $raf = Position::create(['project_id' => $this->prise->id, 'organizational_unit_id' => $this->cn->id, 'name' => 'RAF', 'is_active' => true]);

        $opts = $this->getJson('/api/auth/registration-options?project=PRISE')->assertOk()->json('positions');
        $this->assertSame($this->kananga->id, collect($opts)->firstWhere('name', 'Logisticien')['unitId']);

        $form = ['project' => 'PRISE', 'name' => 'Jean Agent', 'email' => 'jean@example.test', 'password' => 'Secret-2026', 'password_confirmation' => 'Secret-2026',
            'organizational_unit_id' => $this->kananga->id, 'position_id' => $raf->id];
        $this->postJson('/api/auth/register', $form)->assertStatus(422);
        $kgaLogi = Position::where('name', 'Logisticien')->where('organizational_unit_id', $this->kananga->id)->value('id');
        $this->postJson('/api/auth/register', ['position_id' => $kgaLogi] + $form)->assertSuccessful();

        $u = User::where('email', 'jean@example.test')->first();
        $super = $this->user(['is_super_admin' => true, 'role' => 'ADMINISTRATEUR', 'organizational_unit_id' => $this->cn->id]);
        $this->as($super)->putJson("/api/users/{$u->id}/assignment", ['organizational_unit_id' => $this->kananga->id, 'position_id' => $raf->id])->assertStatus(422)->assertJsonValidationErrors('position_id');
    }

    public function test_tableau_de_bord_selon_les_habilitations(): void
    {
        $this->as($this->user())->getJson('/api/logistics/dashboard')->assertForbidden();

        $stockOnly = $this->user(['can_view_stock' => true, 'organizational_unit_id' => $this->kananga->id]);
        $d = $this->as($stockOnly)->getJson('/api/logistics/dashboard')->assertOk()->json();
        $this->assertNotNull($d['stock']);
        $this->assertNull($d['vehicles']);
        $this->assertSame($this->kananga->id, $d['unitId']);

        $a = $this->article('Cartouche', 45);
        $a->forceFill(['alert_threshold' => 5])->save();
        $agent = $this->user(['can_manage_stock' => true, 'organizational_unit_id' => $this->kananga->id]);
        $this->as($agent)->postJson('/api/stock/movements', ['stock_article_id' => $a->id, 'movement_date' => now()->toDateString(), 'type' => 'ENTREE', 'quantity' => 4, 'origin_destination' => 'Approvisionnement'])->assertOk();

        $all = $this->user(['role' => 'ADMINISTRATEUR', 'can_view_pools' => true, 'organizational_unit_id' => $this->cn->id]);
        $d = $this->as($all)->getJson('/api/logistics/dashboard')->assertOk()->json();
        $this->assertNull($d['unitId']);
        $this->assertSame(1, $d['stock']['low']);
        $this->assertEquals(180, $d['stock']['monthIn']['amount']);
        $this->assertSame('Cartouche', $d['stock']['alerts'][0]['designation']);
        $this->assertSame(0, $d['vehicles']['count']);
        $this->assertArrayHasKey('month', $d['generators']);
    }

    public function test_logisticien_national_gere_sa_logistique_et_consulte_les_antennes(): void
    {
        $logi = ['can_view_stock' => true, 'can_manage_stock' => true, 'can_view_fleet' => true, 'can_manage_fleet' => true];
        $national = $this->user($logi + ['can_view_pools' => true, 'organizational_unit_id' => $this->cn->id]);
        $kga = $this->user($logi + ['organizational_unit_id' => $this->kananga->id]);
        $ca = $this->user(['can_authorize_fleet' => true, 'organizational_unit_id' => $this->kananga->id]);
        $a = $this->article('Rame A4', 6.5);
        $this->as($kga)->postJson('/api/stock/movements', ['stock_article_id' => $a->id, 'movement_date' => '2026-10-01', 'type' => 'ENTREE', 'quantity' => 10, 'origin_destination' => 'Livraison'])->assertOk();
        $asset = ['kind' => 'VEHICULE', 'name' => 'Hilux CN', 'organizational_unit_id' => $this->cn->id, 'tank_capacity' => '120', 'consumption_rate' => '0,2', 'fuel_price' => '1,37',
            'maintenance_interval' => 3000, 'maintenance_warning' => 2500, 'initial_meter' => '1000'];
        $kgaAsset = $this->as($kga)->postJson('/api/fleet/assets', ['name' => 'Fortuner KGA', 'organizational_unit_id' => $this->kananga->id] + $asset)->assertOk()->json('id');

        // Toutes les opérations dans sa propre unité : parc, catalogue, stock.
        $this->as($national)->postJson('/api/fleet/assets', $asset)->assertOk();
        $this->as($national)->postJson('/api/stock/articles', ['designation' => 'Stylos', 'unit' => 'Boîte', 'unit_price' => '3'])->assertOk();
        $this->as($national)->postJson('/api/stock/movements', ['stock_article_id' => $a->id, 'movement_date' => '2026-10-02', 'type' => 'ENTREE', 'quantity' => 4, 'origin_destination' => 'Livraison'])->assertOk();

        // La situation des antennes en lecture seule.
        $stock = $this->as($national)->getJson('/api/stock')->assertOk()->json();
        $this->assertEquals(14, $stock['articles'][0]['stock']);
        $this->assertSame($this->cn->id, $stock['ownUnitId']);
        $card = $this->as($national)->getJson("/api/stock/articles/{$a->id}/card?unit_id={$this->kananga->id}")->assertOk()->json();
        $this->assertFalse($card['canWrite']);
        $this->assertTrue($card['otherUnit']);
        $book = $this->as($national)->getJson("/api/fleet/assets/{$kgaAsset}/logbook")->assertOk()->json();
        $this->assertFalse($book['canWrite']);
        $this->assertTrue($book['otherUnit']);

        // Aucun ajout ni modification dans une antenne.
        $this->as($national)->postJson('/api/stock/movements', ['stock_article_id' => $a->id, 'organizational_unit_id' => $this->kananga->id, 'movement_date' => '2026-10-03', 'type' => 'SORTIE', 'quantity' => 1, 'origin_destination' => 'HNSE'])
            ->assertForbidden();
        $this->assertSame(10.0, (float) DB::table('stock_movements')->where('organizational_unit_id', $this->kananga->id)->sum('quantity'));
        $this->as($national)->postJson('/api/fleet/logs', ['fleet_asset_id' => $kgaAsset, 'log_date' => '2026-10-01', 'requester' => 'RAF', 'destination' => 'Bureau', 'meter_start' => 1000, 'meter_end' => 1010, 'assigned_to' => $ca->id])->assertForbidden();
        $this->as($national)->putJson("/api/fleet/assets/{$kgaAsset}/toggle")->assertForbidden();
        $this->as($national)->postJson('/api/fleet/maintenances', ['fleet_asset_id' => $kgaAsset, 'maintenance_date' => '2026-10-01', 'meter_reading' => '1000', 'designation' => 'Vidange'])->assertForbidden();
    }
}

