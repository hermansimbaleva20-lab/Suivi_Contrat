<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\Dates;
use App\Support\Loader;
use App\Support\Metrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Marchés de biens : procédure BAD ou nationale, avance sur demande, pénalité de retard ; les fournitures de bureau ne changent pas. */
class GoodsProcedureApiTest extends TestCase
{
    use RefreshDatabase;

    private Project $prise;
    private ProjectPhase $phase;
    private int $company;
    private int $cn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prise = Project::create(['code' => 'PRISE', 'name' => 'PRISE']);
        $this->phase = ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 1, 'name' => 'Phase 1', 'is_current' => true, 'is_archived' => false]);
        $this->company = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'Fournisseur', 'created_at' => now(), 'updated_at' => now()]);
        $this->cn = $this->unit('COORDINATION', 'CN', 'Coordination nationale');
    }

    private function unit(string $type, string $code, string $name): int
    {
        return DB::table('organizational_units')->insertGetId(['project_id' => $this->prise->id, 'type' => $type, 'code' => $code, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(array $attrs): User
    {
        $u = new User();
        $u->forceFill(array_merge(['project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false], $attrs))->save();
        return $u;
    }

    private function as(User $u): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    private function form(array $extra = []): array
    {
        return $extra + ['reference' => '200', 'company_id' => $this->company, 'category' => 'BIENS', 'selected_provinces' => ['Kasaï-Central'], 'start_date' => '2025-01-01', 'duration_months' => 6, 'amount' => '100000',
            'initial_financing_rate' => '10', 'guarantee_rate' => '10', 'guarantee_bank_name' => 'Rawbank', 'sites' => [['name' => 'Kananga', 'province' => 'Kasaï-Central']],
            'goods_procedure' => 'BAD', 'goods_origin' => 'LOCAL'];
    }

    private function national(User $u, array $extra = []): int
    {
        return $this->as($u)->postJson('/api/contracts', $this->form($extra + ['goods_procedure' => 'NATIONALE', 'initial_financing_rate' => '20', 'guarantee_rate' => '5', 'delay_penalty_rate' => '0,05', 'delay_penalty_cap' => '10']))->assertCreated()->json('id');
    }

    private function advanceForm(array $extra = []): array
    {
        return $extra + ['request_date' => '2025-01-10', 'request_reference' => 'Lettre 12/2025', 'guarantee_bank' => 'Rawbank', 'guarantee_number' => 'GR-01', 'guarantee_amount' => '10000', 'guarantee_end_date' => '2025-07-01'];
    }

    public function test_creation_d_un_marche_selon_la_procedure(): void
    {
        $u = $this->user(['can_manage_contracts' => true]);
        $this->as($u)->getJson('/api/reference-data')->assertOk()->assertJsonPath('goodsProcedures.rules.NATIONALE.LOCAL.advanceRate', 20);

        $this->as($u)->postJson('/api/contracts', $this->form(['goods_procedure' => '']))->assertStatus(422)->assertJsonValidationErrors('goods_procedure');
        $this->as($u)->postJson('/api/contracts', $this->form(['goods_origin' => '']))->assertStatus(422)->assertJsonValidationErrors('goods_origin');
        $this->as($u)->postJson('/api/contracts', $this->form(['initial_financing_rate' => '15']))->assertStatus(422)->assertJsonValidationErrors('payment_schedule');

        $id = $this->as($u)->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $d = $this->as($u)->getJson("/api/contracts/{$id}")->assertOk()->json();
        $this->assertSame(['BAD', 'LOCAL', true, false], [$d['contract']['goodsProcedure'], $d['contract']['goodsOrigin'], $d['contract']['advanceOnRequest'], $d['contract']['canRenew']]);
        $this->assertSame(['LIVRAISON', 'ACCEPTATION'], array_column($d['contract']['paymentSchedule'], 'key'));
        $this->assertEquals(10000, $d['contract']['advanceDue']);
        $this->assertEquals(0, $d['contract']['initialFinancing']); // pas encore demandée
        $this->assertEquals(10000, $d['guarantee']['amount']); // 10 % de garantie en procédure BAD
        $this->assertNotEmpty($d['goodsRules']['documents']);
        $this->assertNull($d['contract']['delayPenaltyRate']);

        // Taux modifiables, tant que le total fait 100 %.
        $other = $this->as($u)->postJson('/api/contracts', $this->form(['reference' => '201', 'initial_financing_rate' => '20', 'payment_schedule' => [['key' => 'LIVRAISON', 'rate' => '70'], ['key' => 'ACCEPTATION', 'rate' => '10']]]))->assertCreated()->json('id');
        $this->assertEquals(70, $this->as($u)->getJson("/api/contracts/{$other}")->json('contract.paymentSchedule.0.rate'));

        // Le renouvellement est réservé aux fournitures de bureau.
        $this->as($u)->postJson("/api/contracts/{$id}/renew", ['reference' => '200/R1', 'date' => '2025-07-01', 'period_start' => '2025-07-01', 'months' => 12, 'added_amount' => '100', 'reason' => 'Nouvelle période'])->assertStatus(422);
    }

    public function test_fournitures_de_bureau_suivies_par_le_stock_seulement(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_stock' => true, 'organizational_unit_id' => $this->cn, 'can_manage_payments' => true, 'can_manage_progress' => true, 'can_validate_decomptes' => true]);
        // Ni banque ni garantie à renseigner ; le taux d'avance transmis est ignoré.
        $id = $this->as($u)->postJson('/api/contracts', $this->form(['goods_procedure' => 'FOURNITURES_BUREAU', 'goods_origin' => 'LOCAL', 'initial_financing_rate' => '20', 'guarantee_bank_name' => '', 'guarantee_rate' => '0']))->assertCreated()->json('id');
        $this->assertDatabaseMissing('performance_guarantees', ['contract_id' => $id]);
        $d = $this->as($u)->getJson("/api/contracts/{$id}")->json();
        $this->assertSame(['FOURNITURES_BUREAU', null, false, true, true, null, [], null], [$d['contract']['goodsProcedure'], $d['contract']['goodsOrigin'], $d['contract']['advanceOnRequest'], $d['contract']['stockOnly'], $d['contract']['canRenew'], $d['goodsRules'], $d['contract']['paymentSchedule'], $d['guarantee']]);
        $this->assertEquals(0, $d['contract']['initialFinancingRate']);
        // Pas de montant de contrat, même transmis : seulement la clause des montants d'une commande, repris par défaut.
        $this->assertSame([0.0, 500.0, 5500.0], [(float) $d['contract']['amount'], (float) $d['contract']['goodsMinAmount'], (float) $d['contract']['goodsMaxAmount']]);
        $this->as($u)->postJson('/api/contracts', $this->form(['reference' => '199', 'goods_procedure' => 'FOURNITURES_BUREAU', 'guarantee_bank_name' => '', 'goods_min_amount' => '6000', 'goods_max_amount' => '5500']))->assertStatus(422)->assertJsonValidationErrors('goods_max_amount');
        $this->as($u)->postJson('/api/contracts', $this->form(['reference' => '199', 'goods_procedure' => 'FOURNITURES_BUREAU', 'guarantee_bank_name' => '', 'amount' => '', 'goods_min_amount' => 'abc']))->assertStatus(422)->assertJsonValidationErrors('goods_min_amount');

        // Ni avance, ni décompte, ni paiement, ni avancement.
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertStatus(422);
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2026-01-10', 'gross_amount' => '100'])->assertStatus(422);
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes/release", ['operation_date' => '2026-01-10'])->assertStatus(422);
        $this->as($u)->postJson('/api/payments', ['contract_id' => $id, 'date' => '2026-01-10', 'amount' => '100'])->assertStatus(422);
        $this->as($u)->postJson('/api/progress', ['contract_id' => $id, 'date' => '2026-01-10', 'progress' => '10'])->assertStatus(422);

        // Absent des écrans financiers, de l'avancement et des indicateurs ; présent dans le registre des contrats.
        $other = $this->as($u)->postJson('/api/contracts', $this->form(['reference' => '201']))->assertCreated()->json('id');
        foreach (['/api/decomptes', '/api/payments', '/api/progress'] as $url) {
            $this->assertSame([$other], array_column($this->as($u)->getJson($url)->assertOk()->json('contracts'), 'id'), $url);
        }
        $this->assertSame([$other], array_column(Loader::contracts($this->prise->id, $this->phase->id), 'id'));
        $list = $this->as($u)->getJson('/api/contracts')->assertOk()->json();
        $this->assertSame(2, $list['meta']['total']);
        $this->assertSame(1, array_sum($list['statusCounts']));

        // Le renouvellement reste possible, sans avance ni garantie.
        $this->as($u)->postJson("/api/contracts/{$id}/renew", ['reference' => '200/R1', 'date' => '2026-01-10', 'period_start' => Dates::today(), 'months' => 12, 'added_amount' => '50000', 'reason' => 'Nouvelle période'])->assertOk();
        $d = $this->as($u)->getJson("/api/contracts/{$id}")->json();
        $this->assertSame([0.0, 0.0, null], [(float) $d['contract']['amount'], (float) $d['contract']['initialFinancing'], $d['guarantee']]);
        // Seule la nouvelle période s'affiche : les mois passés ne se cumulent pas.
        $this->assertSame([Dates::today(), 12], [$d['contract']['periodStart'], $d['contract']['periodMonths']]);
        $this->assertSame(Dates::addMonths(Dates::today(), 12), $d['contract']['endDate']);
        $this->assertNotSame(12, $d['contract']['durationMonths']);
        // Corriger le contrat renouvelé ne change ni sa période ni ses montants.
        $site = DB::table('contract_sites')->where('contract_id', $id)->value('id');
        $this->as($u)->putJson("/api/contracts/{$id}", ['reference' => '200/R1', 'company_id' => $this->company, 'category' => 'BIENS', 'start_date' => $d['contract']['startDate'], 'duration_months' => $d['contract']['durationMonths'],
            'goods_max_amount' => '6000', 'sites' => [['id' => $site, 'name' => 'Kananga', 'province' => 'Kasaï-Central']]])->assertOk();
        $d = $this->as($u)->getJson("/api/contracts/{$id}")->json();
        $this->assertSame([Dates::addMonths(Dates::today(), 12), 6000.0, 500.0, 0.0], [$d['contract']['endDate'], (float) $d['contract']['goodsMaxAmount'], (float) $d['contract']['goodsMinAmount'], (float) $d['contract']['amount']]);
    }

    public function test_aucune_alerte_pour_les_fournitures_de_bureau(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_stock' => true, 'organizational_unit_id' => $this->cn, 'can_view_alerts_contracts' => true]);
        DB::table('companies')->where('id', $this->company)->update(['email' => 'fournisseur@example.test']);
        // Contrats terminés depuis longtemps et sans avancement : le contrat de travaux est en retard, pas les fournitures de bureau.
        $office = $this->as($u)->postJson('/api/contracts', $this->form(['reference' => 'FB-200', 'goods_procedure' => 'FOURNITURES_BUREAU', 'guarantee_bank_name' => '']))->assertCreated()->json('id');
        $this->as($u)->postJson('/api/contracts', $this->form(['reference' => 'TX-300', 'category' => 'TRAVAUX', 'province' => 'Kasaï-Central', 'work_mode' => 'Lourd', 'goods_procedure' => '', 'goods_origin' => '']))->assertCreated();
        $this->assertSame('Normal', Metrics::status(['terminated' => false, 'termination_date' => null, 'completed' => false] + Loader::contract($this->prise->id, $this->phase->id, $office), '2025-03-01'));

        Artisan::call('prise:send-alerts', ['--dry-run' => true]);
        $output = Artisan::output();
        $this->assertStringContainsString('TX-300', $output);
        $this->assertStringNotContainsString('FB-200', $output);

        $this->as($u)->getJson('/api/alerts')->assertOk()->assertJsonMissing(['number' => 'FB-200']);
    }

    public function test_stock_d_un_contrat_de_fournitures_de_bureau(): void
    {
        $unit = $this->cn;
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_stock' => true, 'can_view_stock' => true, 'organizational_unit_id' => $unit]);
        $contract = $this->as($u)->postJson('/api/contracts', $this->form(['goods_procedure' => 'FOURNITURES_BUREAU', 'guarantee_bank_name' => '']))->assertCreated()->json('id');
        $article = fn ($no, $name, $cid) => DB::table('stock_articles')->insertGetId(['project_id' => $this->prise->id, 'organizational_unit_id' => $unit, 'contract_id' => $cid, 'number' => $no, 'designation' => $name, 'unit' => 'Pièce', 'unit_price' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $paper = $article(1, 'Ramette de papier', $contract);
        $free = $article(2, 'Balai', null);

        $delivery = fn ($lines, $extra = []) => $extra + ['company_id' => $this->company, 'movement_date' => '2026-02-01', 'reference' => 'BL-1', 'lines' => $lines];
        // Un article d'un autre contrat, ou sans contrat, ne passe pas sur la livraison du contrat : rien n'est enregistré.
        $this->as($u)->postJson('/api/stock/deliveries', $delivery([['stock_article_id' => $paper, 'quantity' => '10'], ['stock_article_id' => $free, 'quantity' => '3']], ['contract_id' => $contract]))->assertStatus(422)->assertJsonValidationErrors('lines.1.stock_article_id');
        $this->assertDatabaseCount('stock_movements', 0);

        $this->as($u)->postJson('/api/stock/deliveries', $delivery([['stock_article_id' => $paper, 'quantity' => '10']], ['contract_id' => $contract]))->assertOk();
        $this->as($u)->postJson('/api/stock/deliveries', $delivery([['stock_article_id' => $free, 'quantity' => '3']]))->assertOk();
        $this->as($u)->postJson('/api/stock/movements', ['stock_article_id' => $paper, 'type' => 'SORTIE', 'movement_date' => '2026-02-03', 'quantity' => '4', 'position_id' => DB::table('positions')->insertGetId(['project_id' => $this->prise->id, 'organizational_unit_id' => $unit, 'name' => 'Logisticien', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()])])->assertSuccessful();

        $data = $this->as($u)->getJson('/api/stock')->assertOk()->json();
        $rows = collect($data['articles'])->keyBy('id');
        $this->assertSame([10.0, 4.0, 6.0, $contract], [(float) $rows[$paper]['entries'], (float) $rows[$paper]['exits'], (float) $rows[$paper]['stock'], $rows[$paper]['contractId']]);
        $this->assertSame([3.0, 0.0, 3.0, null], [(float) $rows[$free]['entries'], (float) $rows[$free]['exits'], (float) $rows[$free]['stock'], $rows[$free]['contractId']]);
        $this->assertSame([$contract], array_column($data['articleContracts'], 'id'));
        $this->assertSame($this->company, $data['articleContracts'][0]['companyId']);

        // Le maximum est une simple clause : une livraison au-delà reste enregistrée.
        $this->as($u)->postJson('/api/stock/deliveries', $delivery([['stock_article_id' => $paper, 'quantity' => '5000']], ['contract_id' => $contract]))->assertOk();

        // Aucun effet sur le contrat : pas de paiement, pas d'avancement.
        $this->assertDatabaseCount('contract_payments', 0);
        $this->assertDatabaseCount('progress_entries', 0);
    }

    public function test_chaque_unite_gere_ses_contrats_de_fournitures(): void
    {
        $kananga = $this->unit('POOL', 'KGA', 'Antenne KANANGA');
        $mbuji = $this->unit('POOL', 'MBM', 'Antenne MBUJI-MAYI');
        $seca = $this->user(['can_manage_stock' => true, 'can_view_stock' => true, 'organizational_unit_id' => $kananga]);
        $autreSeca = $this->user(['can_manage_stock' => true, 'can_view_stock' => true, 'organizational_unit_id' => $mbuji]);
        $logisticien = $this->user(['can_manage_stock' => true, 'can_view_stock' => true, 'can_view_pools' => true, 'organizational_unit_id' => $this->cn]);
        $comptable = $this->user(['can_manage_contracts' => true, 'organizational_unit_id' => $this->cn]);
        $office = fn ($ref) => $this->form(['reference' => $ref, 'goods_procedure' => 'FOURNITURES_BUREAU', 'guarantee_bank_name' => '']);

        // Le SECA crée le contrat de son antenne ; la logistique ne crée aucun autre contrat, et « Gérer les contrats » ne donne rien ici.
        $id = $this->as($seca)->postJson('/api/contracts', $office('FB-KGA'))->assertCreated()->json('id');
        $this->assertSame($kananga, (int) DB::table('contracts')->where('id', $id)->value('organizational_unit_id'));
        $this->as($seca)->postJson('/api/contracts', $this->form(['reference' => 'BAD-1']))->assertForbidden();
        $this->as($comptable)->postJson('/api/contracts', $office('FB-CN'))->assertForbidden();
        $own = $this->as($logisticien)->postJson('/api/contracts', $office('FB-CN'))->assertCreated()->json('id');
        $this->assertSame($this->cn, (int) DB::table('contracts')->where('id', $own)->value('organizational_unit_id'));

        $d = $this->as($seca)->getJson("/api/contracts/{$id}")->assertOk()->json('contract');
        $this->assertSame([true, $kananga, 'Antenne KANANGA'], [$d['canManage'], $d['unitId'], $d['unitName']]);

        // Une autre unité, sans la vue nationale, ne le voit pas.
        foreach ([$autreSeca, $comptable] as $outsider) {
            $this->as($outsider)->getJson("/api/contracts/{$id}")->assertNotFound();
            $this->assertNotContains($id, array_column($this->as($outsider)->getJson('/api/contracts')->assertOk()->json('data'), 'id'));
            $this->as($outsider)->putJson("/api/contracts/{$id}", $office('FB-KGA'))->assertNotFound();
        }

        // Le logisticien national le voit, en lecture seule.
        $d = $this->as($logisticien)->getJson("/api/contracts/{$id}")->assertOk()->json('contract');
        $this->assertFalse($d['canManage']);
        $this->assertContains($id, array_column($this->as($logisticien)->getJson('/api/contracts?size=7')->json('data'), 'id'));
        $site = DB::table('contract_sites')->where('contract_id', $id)->value('id');
        $sites = [['id' => $site, 'name' => 'Kananga', 'province' => 'Kasaï-Central']];
        $this->as($logisticien)->putJson("/api/contracts/{$id}", $office('FB-KGA') + ['sites' => $sites])->assertForbidden();
        $this->as($logisticien)->postJson("/api/contracts/{$id}/terminate", ['reason' => 'Essai de résiliation'])->assertForbidden();
        $renew = ['reference' => 'FB-KGA/R1', 'date' => '2026-01-10', 'period_start' => Dates::today(), 'months' => 12, 'reason' => 'Nouvelle période'];
        $this->as($logisticien)->postJson("/api/contracts/{$id}/renew", $renew)->assertForbidden();
        $this->as($logisticien)->post("/api/contracts/{$id}/documents", ['file' => UploadedFile::fake()->create('contrat.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertForbidden();

        // Le SECA le gère entièrement : modification et renouvellement. Il garde son unité.
        $this->as($seca)->putJson("/api/contracts/{$id}", ['sites' => $sites, 'goods_max_amount' => '6000'] + $office('FB-KGA'))->assertOk();
        $this->as($seca)->postJson("/api/contracts/{$id}/renew", $renew)->assertOk();
        $this->assertSame($kananga, (int) DB::table('contracts')->where('id', $id)->value('organizational_unit_id'));

        // Il ne fait pas sortir le contrat des fournitures de bureau ; il ne gère pas celui de la coordination.
        $this->as($seca)->putJson("/api/contracts/{$id}", ['reference' => 'FB-KGA/R1', 'sites' => $sites] + $this->form())->assertForbidden();
        $this->as($seca)->getJson("/api/contracts/{$own}")->assertNotFound();

        // Dans le stock : chaque unité ne se voit proposer que ses contrats ; le logisticien national voit tout.
        $this->assertSame([$id], array_column($this->as($seca)->getJson('/api/stock')->assertOk()->json('articleContracts'), 'id'));
        $this->assertSame([], $this->as($autreSeca)->getJson('/api/stock')->assertOk()->json('articleContracts'));
        $this->assertEqualsCanonicalizing([$id, $own], array_column($this->as($logisticien)->getJson('/api/stock')->assertOk()->json('articleContracts'), 'id'));
    }

    public function test_changer_de_procedure_ajoute_ou_retire_la_garantie(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_stock' => true, 'organizational_unit_id' => $this->cn, 'can_manage_payments' => true]);
        $id = $this->as($u)->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $this->assertDatabaseHas('performance_guarantees', ['contract_id' => $id]);
        $sites = [['id' => DB::table('contract_sites')->where('contract_id', $id)->value('id'), 'name' => 'Kananga', 'province' => 'Kasaï-Central']];

        // BAD → fournitures de bureau : la garantie disparaît et l'avance tombe à zéro.
        $this->as($u)->putJson("/api/contracts/{$id}", $this->form(['sites' => $sites, 'goods_procedure' => 'FOURNITURES_BUREAU']))->assertOk();
        $this->assertDatabaseMissing('performance_guarantees', ['contract_id' => $id]);
        $this->assertEquals(0, DB::table('contracts')->where('id', $id)->value('initial_financing_rate'));

        // Retour à une procédure : la banque est de nouveau obligatoire, la garantie est recréée.
        $this->as($u)->putJson("/api/contracts/{$id}", $this->form(['sites' => $sites, 'guarantee_bank_name' => '']))->assertStatus(422)->assertJsonValidationErrors('guarantee_bank_name');
        $this->as($u)->putJson("/api/contracts/{$id}", $this->form(['sites' => $sites]))->assertOk();
        $g = DB::table('performance_guarantees')->where('contract_id', $id)->first();
        $this->assertSame(['Rawbank', 10.0, 10000.0], [$g->bank_name, (float) $g->rate, (float) $g->amount]);

        // Un contrat qui a déjà un paiement ne passe pas en fournitures de bureau.
        $this->as($u)->postJson('/api/payments', ['contract_id' => $id, 'date' => '2025-02-01', 'amount' => '100'])->assertCreated();
        $this->as($u)->putJson("/api/contracts/{$id}", $this->form(['sites' => $sites, 'goods_procedure' => 'FOURNITURES_BUREAU']))->assertStatus(422)->assertJsonValidationErrors('goods_procedure');
    }

    public function test_avance_sur_demande_contre_garantie_de_restitution(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $id = $this->as($u)->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $reader = $this->user([]);

        $this->as($reader)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertForbidden();
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm(['guarantee_amount' => '9999,99']))->assertStatus(422)->assertJsonValidationErrors('guarantee_amount');
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm(['guarantee_number' => '']))->assertStatus(422)->assertJsonValidationErrors('guarantee_number');
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm(['guarantee_end_date' => '2025-01-01']))->assertStatus(422)->assertJsonValidationErrors('guarantee_end_date');

        $advance = $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertCreated()->json('id');
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertStatus(422); // une seule demande
        $this->assertDatabaseHas('audit_logs', ['entity_name' => 'ContractAdvance', 'entity_id' => (string) $advance]);

        // Demandée, pas encore payée : rien ne compte, et aucun décompte tant qu'elle n'est pas traitée.
        $c = $this->as($u)->getJson("/api/contracts/{$id}")->json('contract');
        $this->assertSame(['DEMANDEE', 0.0, 100000.0], [$c['advanceRequest']['status'], (float) $c['initialFinancing'], (float) $c['remainingAmount']]);
        $this->assertFalse($this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('canSubmit'));
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-02-01', 'gross_amount' => '80000'])->assertStatus(422);

        $this->as($u)->postJson("/api/advances/{$advance}/pay", ['paid_date' => '2025-01-05', 'payment_reference' => 'VIR-1'])->assertStatus(422)->assertJsonValidationErrors('paid_date');
        $this->as($u)->postJson("/api/advances/{$advance}/pay", ['paid_date' => '2025-01-20', 'payment_reference' => 'VIR-1'])->assertOk();
        $this->as($u)->postJson("/api/advances/{$advance}/pay", ['paid_date' => '2025-01-20', 'payment_reference' => 'VIR-1'])->assertStatus(422);
        $c = $this->as($u)->getJson("/api/contracts/{$id}")->json('contract');
        $this->assertSame([10000.0, 10.0, 90000.0], [(float) $c['initialFinancing'], (float) $c['paymentRate'], (float) $c['remainingAmount']]);

        // Le décompte retient alors l'avance et la garantie aux taux de la procédure : 80 000 − 8 000 − 8 000.
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-02-01', 'gross_amount' => '80000'])->assertOk();
        $row = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('rows.0');
        $this->assertSame([8000.0, 8000.0, 0.0, 64000.0, 2000.0], [(float) $row['advance'], (float) $row['guarantee'], (float) $row['penalty'], (float) $row['net'], (float) $row['advanceBalance']]);

        // L'avance réglée alimente les lignes : son paiement ne s'annule plus, et les règles du marché sont figées.
        $this->as($u)->postJson("/api/advances/{$advance}/cancel", ['reason' => 'Erreur de saisie'])->assertStatus(422);
        $this->as($u)->putJson("/api/contracts/{$id}", $this->form(['goods_origin' => 'ETRANGER']))->assertStatus(422)->assertJsonValidationErrors('goods_procedure');
    }

    public function test_avance_rejetee_redemandee_ou_perdue_apres_un_decompte(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $id = $this->as($u)->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $first = $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertCreated()->json('id');
        $this->as($u)->postJson("/api/advances/{$first}/cancel", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($u)->postJson("/api/advances/{$first}/cancel", ['reason' => 'Garantie non conforme'])->assertOk();
        $this->assertNull($this->as($u)->getJson("/api/contracts/{$id}")->json('contract.advanceRequest'));
        $second = $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertCreated()->json('id');
        $this->as($u)->postJson("/api/advances/{$second}/cancel", ['reason' => 'Demande retirée'])->assertOk();

        // Sans avance, le décompte ne retient rien au titre de l'avance ; elle ne peut plus être demandée ensuite.
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-02-01', 'gross_amount' => '1000'])->assertOk();
        $row = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('rows.0');
        $this->assertSame([0.0, 100.0, 900.0], [(float) $row['advance'], (float) $row['guarantee'], (float) $row['net']]);
        $this->as($u)->postJson("/api/contracts/{$id}/advance", $this->advanceForm())->assertStatus(422);
    }

    public function test_penalite_nationale_deduite_du_dernier_decompte(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $chef = $this->user(['can_validate_decomptes' => true]);
        $id = $this->national($u); // fin du contrat : 01/07/2025
        $this->assertEquals(0.05, $this->as($u)->getJson("/api/contracts/{$id}")->json('contract.delayPenaltyRate'));

        // Décompte intermédiaire, même en retard : aucune pénalité.
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-09-01', 'gross_amount' => '40000'])->assertOk();
        $d = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json();
        $this->assertSame([0.0, 0.0, 2000.0, 38000.0], [(float) $d['rows'][0]['penalty'], (float) $d['rows'][0]['advance'], (float) $d['rows'][0]['guarantee'], (float) $d['rows'][0]['net']]);

        // Il atteint le montant du marché : dernier décompte, date de réception obligatoire.
        $last = ['number' => 'DECOMPTE N°2', 'operation_date' => '2025-09-10', 'gross_amount' => '60000'];
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", $last)->assertStatus(422)->assertJsonValidationErrors('reception_date');
        // 01/07 → 12/08/2025 : 42 jours, 6 semaines × 0,05 % × 100 000 = 300.
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", $last + ['reception_date' => '2025-08-12'])->assertOk()->assertJsonFragment(['message' => 'DECOMPTE N°2 enregistré et transmis au chef de cellule infrastructures pour validation. Pénalité de retard déduite : 300,00 USD (6 semaine(s) de retard).']);
        $d = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json();
        $row = $d['rows'][1];
        $this->assertSame([300.0, 6, true, '2025-08-12'], [(float) $row['penalty'], $row['penaltyWeeks'], $row['isFinal'], $row['receptionDate']]);
        // Sans avance versée : 60 000 − garantie 5 % (3 000) − 300.
        $this->assertSame([3000.0, 56700.0], [(float) $row['guarantee'], (float) $row['net']]);
        $this->assertFalse($d['canSubmit']);
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°3', 'operation_date' => '2025-09-11', 'gross_amount' => '10'])->assertStatus(422);

        // Pénalité validée : hors du reste à payer, et le paiement proposé est net de pénalité.
        $this->assertEquals(0, $this->as($u)->getJson("/api/contracts/{$id}")->json('contract.penalties'));
        foreach ($d['rows'] as $line) $this->as($chef)->postJson("/api/decomptes/{$line['id']}/decision", ['decision' => 'VALIDE'])->assertOk();
        $this->assertEquals(300, $this->as($u)->getJson("/api/contracts/{$id}")->json('contract.penalties'));
        $this->assertEquals(100000 - 300, $this->as($u)->getJson("/api/contracts/{$id}")->json('contract.remainingAmount'));
        $toPay = collect($this->as($u)->getJson("/api/payments?contract_id={$id}")->assertOk()->json('toPay'))->firstWhere('number', 'DECOMPTE N°2');
        $this->assertEquals(56700, $toPay['amount']);
    }

    public function test_reception_a_temps_ou_decompte_marque_comme_dernier(): void
    {
        $u = $this->user(['can_manage_contracts' => true, 'can_manage_payments' => true]);
        $id = $this->national($u);
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-09-01', 'gross_amount' => '1000', 'is_final' => true])->assertStatus(422)->assertJsonValidationErrors('reception_date');
        $this->as($u)->postJson("/api/contracts/{$id}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-09-01', 'gross_amount' => '1000', 'is_final' => true, 'reception_date' => '2025-06-20'])->assertOk();
        $row = $this->as($u)->getJson("/api/decomptes?contract_id={$id}")->json('rows.0');
        $this->assertSame([0.0, 0, true], [(float) $row['penalty'], $row['penaltyWeeks'], $row['isFinal']]);

        // La procédure BAD n'a pas de pénalité : la case et la date n'ont aucun effet.
        $bad = $this->as($u)->postJson('/api/contracts', $this->form(['reference' => '201']))->assertCreated()->json('id');
        $this->as($u)->postJson("/api/contracts/{$bad}/decomptes", ['number' => 'DECOMPTE N°1', 'operation_date' => '2025-09-01', 'gross_amount' => '100000', 'is_final' => true, 'reception_date' => '2025-08-12'])->assertOk();
        $row = $this->as($u)->getJson("/api/decomptes?contract_id={$bad}")->json('rows.0');
        $this->assertSame([0.0, false], [(float) $row['penalty'], $row['isFinal']]);
    }

    public function test_les_regles_restent_modifiables_tant_que_le_marche_n_a_pas_commence(): void
    {
        $u = $this->user(['can_manage_contracts' => true]);
        $id = $this->as($u)->postJson('/api/contracts', $this->form())->assertCreated()->json('id');
        $site = DB::table('contract_sites')->where('contract_id', $id)->value('id');
        $sites = [['id' => $site, 'name' => 'Kananga', 'province' => 'Kasaï-Central']];
        $this->as($u)->putJson("/api/contracts/{$id}", $this->form(['sites' => $sites, 'goods_procedure' => 'NATIONALE', 'initial_financing_rate' => '20', 'guarantee_rate' => '5', 'delay_penalty_rate' => '0.05', 'delay_penalty_cap' => '10', 'payment_schedule' => [['key' => 'RECEPTION', 'rate' => '80']]]))->assertOk();
        $d = $this->as($u)->getJson("/api/contracts/{$id}")->json();
        $this->assertSame('NATIONALE', $d['contract']['goodsProcedure']);
        $this->assertEquals(5, $d['guarantee']['rate']);
        $this->assertEquals(5000, $d['guarantee']['amount']);
    }
}
