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

    /** Article d'une unité (Kananga par défaut) : chaque unité tient les siens. */
    private function article(string $designation = 'Rame papier duplicateur A4', float $price = 6.5, ?OrganizationalUnit $unit = null): StockArticle
    {
        $unitId = ($unit ?? $this->kananga)->id;
        $n = (int) StockArticle::where('project_id', $this->prise->id)->where('organizational_unit_id', $unitId)->max('number') + 1;
        return StockArticle::create(['project_id' => $this->prise->id, 'organizational_unit_id' => $unitId, 'number' => $n, 'designation' => $designation, 'unit' => 'Rame', 'unit_price' => $price, 'is_active' => true]);
    }

    /** Poste d'une unité (null : commun à toutes les unités), créé au besoin. */
    private function position(string $name, ?OrganizationalUnit $unit, bool $active = true): int
    {
        $where = ['project_id' => $this->prise->id, 'organizational_unit_id' => $unit?->id, 'name' => $name];
        return DB::table('positions')->where($where)->value('id') ?: DB::table('positions')->insertGetId($where + ['is_active' => $active, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function move(User $u, StockArticle $a, OrganizationalUnit $unit, string $type, $qty, string $date = '2026-10-01', string $who = 'Approvisionnement')
    {
        return $this->as($u)->postJson('/api/stock/movements', [
            'stock_article_id' => $a->id, 'organizational_unit_id' => $unit->id, 'movement_date' => $date, 'type' => $type, 'quantity' => $qty, 'origin_destination' => $who,
        ] + ($type === 'SORTIE' ? ['position_id' => $this->position($who, $unit)] : []));
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

    public function test_alerte_de_stock_selon_l_unite_de_l_article(): void
    {
        $agent = $this->logisticien($this->kananga);
        $article = function (string $name, string $unit, $qty, $threshold = null) use ($agent) {
            $a = $this->article($name);
            $a->forceFill(['unit' => $unit, 'alert_threshold' => $threshold])->save();
            if ($qty !== null) $this->move($agent, $a, $this->kananga, 'ENTREE', $qty)->assertOk();
            return $a;
        };
        $cases = [
            'Pce à 3' => [$article('Pce à 3', 'Pce', 3), 'OK'],
            'Pce à 2' => [$article('Pce à 2', 'Pce', 2), 'LOW'],
            'Pièce à 1' => [$article('Pièce à 1', 'Pièce', 1), 'LOW'],
            'Pqt à 2' => [$article('Pqt à 2', 'Pqt', 2), 'OK'],
            'Pqt à 1' => [$article('Pqt à 1', 'Pqt', 1), 'LOW'],
            'Pqt/100pces à 1' => [$article('Pqt/100pces à 1', 'Pqt/100pces', 1), 'LOW'],
            'Rame à 1, sans niveau' => [$article('Rame à 1', 'Rame', 1), 'OK'],
            'Pce à 4, niveau saisi 5' => [$article('Pce à 4', 'Pce', 4, 5), 'LOW'],
            'Pce à 2, niveau saisi 1' => [$article('Pce niveau 1', 'Pce', 2, 1), 'OK'],
            'Jamais approvisionné' => [$article('Jamais', 'Pce', null), 'EMPTY'],
        ];
        $rows = collect($this->as($agent)->getJson('/api/stock')->assertOk()->json('articles'))->keyBy('id');
        foreach ($cases as $label => [$a, $level]) $this->assertSame($level, $rows[$a->id]['level'], $label);
        // Le niveau par défaut s'affiche ; le niveau saisi reste distinct, pour le formulaire.
        $default = $rows[$cases['Pce à 2'][0]->id];
        $this->assertSame([2, null], [(int) $default['alertThreshold'], $default['ownAlertThreshold']]);

        // Mêmes règles pour les alertes de l'application et les e-mails ; le texte donne le niveau retenu.
        $alerts = collect(\App\Support\AlertEngine::logistics($this->prise->id, null))->where('category', 'STOCK')->keyBy('itemId');
        foreach ($cases as $label => [$a, $level]) $this->assertSame($level === 'LOW', $alerts->has($a->id), $label);
        $this->assertStringContainsString('plus que 2 Pce (niveau minimum 2)', $alerts[$cases['Pce à 2'][0]->id]['text']);

        // Stock à zéro : rupture, quelle que soit l'unité.
        $rame = $cases['Rame à 1, sans niveau'][0];
        $this->move($agent, $rame, $this->kananga, 'SORTIE', 1, '2026-10-02', 'HNSE')->assertOk();
        $this->assertSame('OUT', collect($this->as($agent)->getJson('/api/stock')->json('articles'))->firstWhere('id', $rame->id)['level']);
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

    public function test_sortie_de_plusieurs_articles_en_tout_ou_rien(): void
    {
        $u = $this->logisticien($this->cn);
        $paper = $this->article('Rame papier duplicateur A4', 6.5, $this->cn);
        $pens = $this->article('Stylo à bille', 6.5, $this->cn);
        $this->move($u, $paper, $this->cn, 'ENTREE', 10, '2026-03-01');
        $this->move($u, $pens, $this->cn, 'ENTREE', 4, '2026-03-01');
        $hnse = $this->position('HNSE', $this->cn);
        $exit = fn (array $lines, array $extra = []) => $extra + ['position_id' => $hnse, 'movement_date' => '2026-03-10', 'lines' => $lines];

        $this->as($this->user(['can_view_stock' => true, 'organizational_unit_id' => $this->cn->id]))->postJson('/api/stock/exits', $exit([['stock_article_id' => $paper->id, 'quantity' => 1]]))->assertForbidden();
        $this->as($u)->postJson('/api/stock/exits', $exit([['stock_article_id' => $paper->id, 'quantity' => 1]], ['position_id' => '']))->assertStatus(422)->assertJsonValidationErrors('position_id');
        $this->as($u)->postJson('/api/stock/exits', $exit([['stock_article_id' => $paper->id, 'quantity' => 1], ['stock_article_id' => $paper->id, 'quantity' => 1]]))->assertStatus(422);

        // Une ligne au-delà du stock : rien n'est enregistré, et l'erreur vise cette ligne.
        $this->as($u)->postJson('/api/stock/exits', $exit([['stock_article_id' => $paper->id, 'quantity' => 3], ['stock_article_id' => $pens->id, 'quantity' => 9]]))->assertStatus(422)->assertJsonValidationErrors('lines.1.quantity');
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SORTIE')->count());
        // Antidatée avant l'approvisionnement : refusée aussi.
        $this->as($u)->postJson('/api/stock/exits', $exit([['stock_article_id' => $paper->id, 'quantity' => 1]], ['movement_date' => '2026-02-01']))->assertStatus(422);

        $this->as($u)->postJson('/api/stock/exits', $exit([['stock_article_id' => $paper->id, 'quantity' => '3,5'], ['stock_article_id' => $pens->id, 'quantity' => 4]], ['observation' => 'Demande du 09/03']))->assertOk();

        $data = $this->as($u)->getJson('/api/stock?unit_id=' . $this->cn->id)->assertOk()->json();
        $rows = collect($data['articles'])->keyBy('id');
        $this->assertSame([10.0, 3.5, 6.5, 6.5], [(float) $rows[$paper->id]['entries'], (float) $rows[$paper->id]['exits'], (float) $rows[$paper->id]['stock'], (float) $rows[$paper->id]['ownStock']]);
        $this->assertSame([4.0, 4.0, 0.0], [(float) $rows[$pens->id]['entries'], (float) $rows[$pens->id]['exits'], (float) $rows[$pens->id]['stock']]);
        $this->assertSame([['id' => $hnse, 'name' => 'HNSE']], $data['positions']);

        // Chaque opération figure sur la fiche de l'article, à sa date, avec le stock restant.
        $card = $this->as($u)->getJson("/api/stock/articles/{$paper->id}/card?unit_id=" . $this->cn->id)->assertOk()->json();
        $line = end($card['lines']);
        $this->assertSame(['2026-03-10', 'HNSE', 3.5, 6.5, 'Demande du 09/03'], [$line['date'], $line['originDestination'], (float) $line['exit'], (float) $line['stock'], $line['observation']]);

        // La vue d'une autre unité ne change pas le stock disponible pour sortir.
        $other = $this->as($u)->getJson('/api/stock?unit_id=' . $this->cn->id)->json('ownUnitId');
        $this->assertSame($this->cn->id, $other);
    }

    public function test_la_destination_d_une_sortie_est_un_poste_de_l_unite_du_compte(): void
    {
        $agent = $this->logisticien($this->kananga);
        $a = $this->article();
        $this->move($agent, $a, $this->kananga, 'ENTREE', 10)->assertOk();
        $own = $this->position('C.P KANANGA', $this->kananga);
        $common = $this->position('Auditeur', null);
        $other = $this->position('C.P MBUJI-MAYI', $this->mbujiMayi);
        $national = $this->position('HNSE', $this->cn);
        $inactive = $this->position('Ancien poste', $this->kananga, false);

        // La liste ne propose que les postes actifs de l'unité, et ceux qui sont communs à toutes.
        $expected = [['id' => $common, 'name' => 'Auditeur'], ['id' => $own, 'name' => 'C.P KANANGA']];
        $this->assertSame($expected, $this->as($agent)->getJson('/api/stock')->assertOk()->json('positions'));
        $this->assertSame($expected, $this->as($agent)->getJson("/api/stock/articles/{$a->id}/card")->assertOk()->json('positions'));

        $exit = fn ($position) => ['position_id' => $position, 'movement_date' => '2026-10-02', 'lines' => [['stock_article_id' => $a->id, 'quantity' => 1]]];
        $movement = fn ($extra) => $extra + ['stock_article_id' => $a->id, 'movement_date' => '2026-10-02', 'type' => 'SORTIE', 'quantity' => 1];
        foreach ([$other, $national, $inactive, 999999] as $refused) {
            $this->as($agent)->postJson('/api/stock/exits', $exit($refused))->assertStatus(422)->assertJsonValidationErrors('position_id');
            $this->as($agent)->postJson('/api/stock/movements', $movement(['position_id' => $refused]))->assertStatus(422)->assertJsonValidationErrors('position_id');
        }
        // Une destination libre n'est plus acceptée.
        $this->as($agent)->postJson('/api/stock/movements', $movement(['origin_destination' => 'Texte libre']))->assertStatus(422)->assertJsonValidationErrors('position_id');
        $this->assertSame(0, DB::table('stock_movements')->where('type', 'SORTIE')->count());

        // La fiche garde le libellé du poste, sur les deux chemins de saisie.
        $this->as($agent)->postJson('/api/stock/exits', $exit($own))->assertOk()->assertJsonFragment(['message' => 'Sortie enregistrée : 1 article(s) remis à C.P KANANGA.']);
        $this->as($agent)->postJson('/api/stock/movements', $movement(['position_id' => $common, 'origin_destination' => 'Texte libre']))->assertOk();
        $this->assertSame(['C.P KANANGA', 'Auditeur'], DB::table('stock_movements')->where('type', 'SORTIE')->orderBy('id')->pluck('origin_destination')->all());
    }

    public function test_un_agent_de_pool_ne_voit_et_ne_saisit_que_son_pool(): void
    {
        $a = $this->article();
        $kga = $this->logisticien($this->kananga);
        $mbm = $this->logisticien($this->mbujiMayi);
        $this->move($kga, $a, $this->kananga, 'ENTREE', 10)->assertOk();

        $this->move($mbm, $a, $this->kananga, 'ENTREE', 10)->assertForbidden();
        // L'article d'une autre unité n'existe pas pour elle, même en l'enregistrant chez elle.
        $this->as($mbm)->getJson("/api/stock/articles/{$a->id}/card?unit_id={$this->kananga->id}")->assertNotFound();
        $this->move($mbm, $a, $this->mbujiMayi, 'ENTREE', 10)->assertNotFound();

        $own = $this->as($mbm)->getJson('/api/stock?unit_id=0')->assertOk()->json();
        $this->assertSame($this->mbujiMayi->id, $own['unitId']);
        $this->assertSame([$this->mbujiMayi->id], array_column($own['units'], 'id'));
        $this->assertSame([], $own['articles']);
        $this->assertFalse($own['canChooseUnit']);
    }

    public function test_la_vue_nationale_designee_consolide_sans_ecrire_dans_les_pools(): void
    {
        $a = $this->article();
        $b = $this->article('Rame papier duplicateur A4', 6.5, $this->mbujiMayi);
        $own = $this->article('Rame papier duplicateur A4', 6.5, $this->cn);
        $this->move($this->logisticien($this->kananga), $a, $this->kananga, 'ENTREE', 10)->assertOk();
        $this->move($this->logisticien($this->mbujiMayi), $b, $this->mbujiMayi, 'ENTREE', 4)->assertOk();

        // La coordination ne voit que sa propre fiche ; la vue des pools est désignée par le super-admin.
        $this->assertSame([$this->cn->id], array_column($this->as($this->logisticien($this->cn))->getJson('/api/stock')->json('units'), 'id'));
        $cn = $this->user(['can_manage_stock' => true, 'can_view_pools' => true, 'organizational_unit_id' => $this->cn->id]);
        $all = $this->as($cn)->getJson('/api/stock')->assertOk()->json();
        $this->assertNull($all['unitId']);
        $rows = collect($all['articles'])->keyBy('id');
        $this->assertSame([10.0, 4.0, 0.0], [(float) $rows[$a->id]['stock'], (float) $rows[$b->id]['stock'], (float) $rows[$own->id]['stock']]);
        $this->assertSame(['Unité KANANGA', false], [$rows[$a->id]['ownerUnit'], $rows[$a->id]['canEdit']]);
        $this->assertEquals(91, $all['totals']['value']);
        $this->assertSame([$this->cn->id], $all['writableUnitIds']);
        // Une unité choisie ne montre que ses articles.
        $this->assertSame([$a->id], array_column($this->as($cn)->getJson("/api/stock?unit_id={$this->kananga->id}")->json('articles'), 'id'));

        $this->move($cn, $a, $this->kananga, 'SORTIE', 1, '2026-10-02', 'HNSE')->assertForbidden();
        $this->as($cn)->putJson("/api/stock/articles/{$a->id}/toggle")->assertForbidden();
        $this->move($cn, $a, $this->cn, 'ENTREE', 2)->assertStatus(422); // l'article d'une antenne n'entre pas au stock de la coordination
        $this->move($cn, $own, $this->cn, 'ENTREE', 2)->assertOk();
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
        $national = $this->as($cn)->postJson('/api/stock/articles', ['designation' => 'Rame papier A4', 'unit' => 'Rame', 'unit_price' => '6,5'])->assertOk()->json('id');
        $this->assertSame([$this->cn->id, 1], [StockArticle::find($national)->organizational_unit_id, StockArticle::find($national)->number]);

        // Sans contrat : article ajouté un par un, propre à l'antenne.
        $propre = $this->as($kga)->postJson('/api/stock/articles', ['designation' => 'Cadenas', 'unit' => 'Pce', 'unit_price' => '4'])->assertOk()->json('id');
        // Chaque unité numérote ses fiches à partir de 1, et une désignation n'est unique que dans l'unité.
        $this->assertSame([$this->kananga->id, 1], [StockArticle::find($propre)->organizational_unit_id, StockArticle::find($propre)->number]);
        $this->as($kga)->postJson('/api/stock/articles', ['designation' => 'cadenas', 'unit' => 'Pce', 'unit_price' => '7'])->assertStatus(422)->assertJsonValidationErrors('designation');
        $this->as($kga)->postJson('/api/stock/articles', ['designation' => 'rame papier a4', 'unit' => 'Rame', 'unit_price' => '7'])->assertOk();
        // Une autre antenne peut avoir son propre « Cadenas » ; elle ne voit ni ne modifie celui de Kananga.
        $this->as($mbm)->postJson('/api/stock/articles', ['designation' => 'Cadenas', 'unit' => 'Pce', 'unit_price' => '5'])->assertOk();
        $this->assertNotContains($propre, array_column($this->as($mbm)->getJson('/api/stock')->json('articles'), 'id'));
        $this->as($mbm)->putJson("/api/stock/articles/{$propre}/toggle")->assertNotFound();
        // Les articles de la coordination lui appartiennent : une antenne ne les voit pas et n'y touche pas.
        $this->as($kga)->putJson("/api/stock/articles/{$national}/toggle")->assertNotFound();
        $view = collect($this->as($kga)->getJson('/api/stock')->json('articles'))->keyBy('id');
        $this->assertFalse($view->has($national));
        $this->assertTrue($view[$propre]['canEdit']);
        $this->assertSame('Unité KANANGA', $view[$propre]['ownerUnit']);
        $this->move($kga, StockArticle::find($propre), $this->kananga, 'ENTREE', 5)->assertOk();

        // Par contrat : import du fichier des articles du contrat.
        $company = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'Fournisseur', 'created_at' => now(), 'updated_at' => now()]);
        $contract = DB::table('contracts')->insertGetId(['project_id' => $this->prise->id, 'phase_id' => DB::table('project_phases')->value('id'), 'company_id' => $company, 'reference' => 'F-12',
            'category' => 'BIENS', 'goods_procedure' => 'FOURNITURES_BUREAU', 'organizational_unit_id' => $this->kananga->id, 'amount' => 1000, 'start_date' => '2026-01-01', 'duration_months' => 6, 'created_at' => now(), 'updated_at' => now()]);
        $csv = "N°;ARTICLE;UNITE;QUANTITE;PU \$;PT \$\n;Toner HP 85A;Pce;4;45;180\n";
        // Le contrat de fournitures d'une autre unité n'est pas accepté.
        $this->as($mbm)->post('/api/stock/import', ['file' => UploadedFile::fake()->createWithContent('contrat.csv', $csv), 'contract_id' => $contract], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('contract_id');
        $this->as($kga)->post('/api/stock/import', ['file' => UploadedFile::fake()->createWithContent('contrat.csv', $csv), 'contract_id' => $contract, 'initial_stock' => 1, 'movement_date' => '2026-10-01'])->assertOk();
        $toner = StockArticle::where('designation', 'Toner HP 85A')->first();
        $this->assertSame($contract, $toner->contract_id);
        $this->assertSame($this->kananga->id, $toner->organizational_unit_id);
        $this->assertSame('F-12', collect($this->as($kga)->getJson('/api/stock')->json('articles'))->firstWhere('id', $toner->id)['contractNumber']);
    }

    public function test_catalogue_de_la_coordination(): void
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
        $this->move($this->admin(), $a, $this->cn, 'ENTREE', 3)->assertOk();
        $this->as($cn)->deleteJson("/api/stock/articles/{$id}")->assertStatus(422);
        $this->as($cn)->putJson("/api/stock/articles/{$id}/toggle")->assertOk();
        $this->move($this->admin(), $a->fresh(), $this->kananga, 'ENTREE', 3)->assertStatus(422);

        $vide = $this->as($cn)->postJson('/api/stock/articles', ['designation' => 'Binder 30', 'unit' => 'Pqt', 'unit_price' => '20'])->json('id');
        $this->as($cn)->deleteJson("/api/stock/articles/{$vide}")->assertOk();
    }
}
