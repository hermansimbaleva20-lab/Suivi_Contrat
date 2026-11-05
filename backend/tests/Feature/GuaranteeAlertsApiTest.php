<?php

namespace Tests\Feature;

use App\Models\OrganizationalUnit;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\AlertEngine;
use App\Support\Dates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Relances des garanties adressées à l'entreprise : séquence, copie à l'équipe, interruption, arrêt à la prolongation. */
class GuaranteeAlertsApiTest extends TestCase
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
        $this->phase = ProjectPhase::create(['project_id' => $this->prise->id, 'number' => 2, 'name' => 'Phase 2', 'is_current' => true, 'is_archived' => false]);
        $this->cn = OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => 'COORDINATION', 'code' => 'CN', 'name' => 'Coordination nationale', 'is_active' => true]);
        $this->pool = OrganizationalUnit::create(['project_id' => $this->prise->id, 'type' => 'POOL', 'code' => 'KGA', 'name' => 'Antenne KANANGA', 'is_active' => true]);
    }

    private function user(array $attrs = []): User
    {
        $u = new User();
        $u->forceFill(array_merge([
            'project_id' => $this->prise->id, 'name' => 'Agent', 'full_name' => 'Agent', 'email' => uniqid('u') . '@example.test', 'password' => Hash::make('secret123'),
            'role' => 'UTILISATEUR', 'is_active' => true, 'is_pending' => false, 'is_super_admin' => false, 'organizational_unit_id' => $this->cn->id,
        ], $attrs))->save();
        return $u;
    }

    private function as(User $u): static
    {
        $this->actingAs($u, 'sanctum');
        return $this->withHeaders(['X-Project' => 'PRISE', 'Accept' => 'application/json']);
    }

    /** Garantie consommée à 80 % (vigilance, 1 envoi par semaine). */
    private function guarantee(?string $email = 'direction@entreprise.test'): int
    {
        $today = Dates::today();
        $co = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'Entreprise ' . uniqid(), 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
        $c = DB::table('contracts')->insertGetId(['project_id' => $this->prise->id, 'phase_id' => $this->phase->id, 'company_id' => $co, 'reference' => '144/PRISE/' . uniqid(), 'category' => 'TRAVAUX',
            'amount' => 1000, 'start_date' => Dates::addDays($today, -80), 'end_date' => Dates::addDays($today, 20), 'duration_months' => 3, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('performance_guarantees')->insertGetId(['project_id' => $this->prise->id, 'contract_id' => $c, 'company_id' => $co, 'bank_name' => 'Rawbank', 'account_number' => 'GBE-' . $c, 'rate' => 5, 'amount' => 50,
            'start_date' => Dates::addDays($today, -80), 'end_date' => Dates::addDays($today, 20), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_relance_envoyee_a_l_entreprise_avec_l_equipe_en_copie(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR']);
        $id = $this->guarantee();
        DB::table('alert_recipients')->insert(['project_id' => $this->prise->id, 'email' => 'raf@example.test', 'categories' => 'GARANTIE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $row = collect($this->as($admin)->getJson('/api/guarantees/alerts')->assertOk()->assertJsonPath('canManage', true)->json('rows'))->firstWhere('id', $id);
        $this->assertSame(['EN_COURS', 'VIGILANCE', 1, true], [$row['state'], $row['kind'], $row['perWeek'], $row['due']]);
        $this->assertStringStartsWith("[PRISE II] Garantie de bonne exécution n° {$row['guaranteeNumber']} — contrat n° 144/PRISE/", $row['subject']);
        $this->assertStringContainsString("garantie de bonne exécution n° {$row['guaranteeNumber']} relative au contrat", $row['text']);
        $this->assertStringContainsString('arrive à échéance le ' . Dates::fr(Dates::addDays(Dates::today(), 20)), $row['text']);

        $plan = collect(AlertEngine::run($this->prise->id, true))->where('category', 'GARANTIE')->values();
        $this->assertCount(2, $plan);
        $this->assertSame(['direction@entreprise.test'], $plan[0]['recipients']); // l'entreprise seule, sans le personnel en copie
        $this->assertStringContainsString('alerte de vigilance', $plan[0]['subject']);
        $this->assertSame(['raf@example.test'], $plan[1]['recipients']);
        $this->assertStringContainsString('Pour attention', $plan[1]['subject']);

        // Sans SMTP, l'envoi échoue et ne consomme pas la séquence ; un envoi réussi aujourd'hui la consomme.
        AlertEngine::run($this->prise->id, false);
        $this->assertSame('ECHEC', DB::table('alert_dispatches')->value('status'));
        $this->assertTrue(collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->firstWhere('id', $id)['due']);
        DB::table('alert_dispatches')->update(['status' => 'ENVOYE']);
        $row = collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->firstWhere('id', $id);
        $this->assertSame([false, 1], [$row['due'], $row['sentCount']]);
        $this->assertCount(0, collect(AlertEngine::run($this->prise->id, true))->where('category', 'GARANTIE'));
    }

    public function test_l_administrateur_interrompt_ou_modifie_la_sequence(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR']);
        $antenne = $this->user(['role' => 'ADMINISTRATEUR', 'organizational_unit_id' => $this->pool->id]);
        $id = $this->guarantee();

        $this->as($antenne)->getJson('/api/guarantees/alerts')->assertOk()->assertJsonPath('canManage', false);
        $this->as($antenne)->putJson("/api/guarantees/{$id}/alerts", ['paused' => true, 'reason' => 'Dossier en cours'])->assertForbidden();

        $this->as($admin)->putJson("/api/guarantees/{$id}/alerts", ['paused' => true])->assertStatus(422);
        $this->as($admin)->putJson("/api/guarantees/{$id}/alerts", ['paused' => true, 'reason' => 'Prolongation annoncée par la banque'])->assertOk();
        $row = collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->firstWhere('id', $id);
        $this->assertSame(['SUSPENDUE', false, 'Prolongation annoncée par la banque'], [$row['state'], $row['due'], $row['pauseReason']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'RELANCES_GARANTIE', 'entity_id' => (string) $id]);

        $this->as($admin)->putJson("/api/guarantees/{$id}/alerts", ['paused' => false, 'per_week' => 3])->assertOk();
        $row = collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->firstWhere('id', $id);
        $this->assertSame(['EN_COURS', 3, 1], [$row['state'], $row['perWeek'], $row['autoPerWeek']]);

        // Paliers du projet : premier seuil relevé à 90 % → plus de relance à 80 % ; interruption générale.
        $this->as($admin)->putJson('/api/guarantees/alerts/settings', ['is_enabled' => true, 'vigilance' => [['from' => 90, 'perWeek' => 2]], 'retard' => 5])->assertOk();
        $this->assertSame('AUCUNE', collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->firstWhere('id', $id)['state']);
        $this->as($admin)->putJson('/api/guarantees/alerts/settings', ['is_enabled' => false, 'vigilance' => [['from' => 75, 'perWeek' => 1]], 'retard' => 4])->assertOk();
        $this->assertSame('DESACTIVEE', collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->firstWhere('id', $id)['state']);
    }

    public function test_la_prolongation_arrete_les_relances_et_l_adresse_vient_de_l_entreprise(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'can_manage_guarantees' => true]);
        $id = $this->guarantee();
        $sans = $this->guarantee(null);
        $end = substr(DB::table('performance_guarantees')->where('id', $id)->value('end_date'), 0, 10);

        $this->as($admin)->postJson("/api/guarantees/{$id}/extend", ['start_date' => $end, 'end_date' => Dates::addDays($end, 365), 'reason' => 'Avenant de prolongation'])->assertOk();
        $rows = collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->keyBy('id');
        $this->assertSame(['AUCUNE', null], [$rows[$id]['state'], $rows[$id]['kind']]);
        $this->assertSame('SANS_ADRESSE', $rows[$sans]['state']);

        $plan = collect(AlertEngine::run($this->prise->id, false))->where('category', 'GARANTIE')->values();
        $this->assertSame('SANS_ADRESSE', $plan[0]['status']);
        $this->assertSame(0, DB::table('alert_dispatches')->where('category', 'GARANTIE')->count());
    }

    public function test_modeles_modifiables_et_historique_par_numero_de_garantie(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'can_view_alert_history' => true]);
        $antenne = $this->user(['role' => 'ADMINISTRATEUR', 'can_view_alert_history' => true, 'organizational_unit_id' => $this->pool->id]);
        $id = $this->guarantee();
        $autre = $this->guarantee();
        $number = DB::table('performance_guarantees')->where('id', $id)->value('account_number');

        $this->assertCount(8, $this->as($antenne)->getJson('/api/alerts/templates')->assertOk()->assertJsonPath('canEdit', false)->json('templates'));
        $tpl = ['subject' => 'Garantie n° {numero_garantie} — {contrat}', 'body' => "Cher partenaire,\n\nVotre garantie n° {numero_garantie} expire le {echeance}.\n\n{projet}"];
        $this->as($antenne)->putJson('/api/alerts/templates/GARANTIE_VIGILANCE', $tpl)->assertForbidden();
        $this->as($admin)->putJson('/api/alerts/templates/GARANTIE_VIGILANCE', ['subject' => 'X {avancement}', 'body' => $tpl['body']])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->as($admin)->putJson('/api/alerts/templates/GARANTIE_VIGILANCE', $tpl)->assertOk();

        $row = collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->firstWhere('id', $id);
        $this->assertStringStartsWith("Garantie n° {$number} — 144/PRISE/", $row['subject']);
        $this->assertStringContainsString("Votre garantie n° {$number} expire le", $row['text']);

        // Envoi manuel : sans SMTP il échoue, mais il est tracé avec le n° de garantie et le texte envoyé.
        $preview = $this->as($admin)->postJson('/api/alerts/send', ['type' => 'garantie', 'id' => $id, 'preview' => true])->assertOk()->json();
        $this->assertSame(['direction@entreprise.test'], $preview['to']);
        $this->as($admin)->postJson('/api/alerts/send', ['type' => 'garantie', 'id' => $id])->assertStatus(422);
        $this->as($admin)->postJson('/api/alerts/send', ['type' => 'garantie', 'id' => $autre])->assertStatus(422);

        $h = $this->as($admin)->getJson("/api/alerts/history?guarantee_id={$id}")->assertOk()->json();
        $this->assertSame(1, $h['total']);
        $this->assertSame([$number, 'VIGILANCE', 'ECHEC', 'GARANTIE'], [$h['data'][0]['guaranteeNumber'], $h['data'][0]['type'], $h['data'][0]['status'], $h['data'][0]['scope']]);
        $this->assertStringContainsString("Votre garantie n° {$number}", $h['data'][0]['body']);
        $this->assertContains($number, array_column($h['guarantees'], 'number'));
        $this->assertSame(2, $this->as($admin)->getJson('/api/alerts/history?scope=GARANTIE')->json('total'));
        $this->assertSame(0, $this->as($admin)->getJson('/api/alerts/history?scope=CONTRAT')->json('total'));

        $this->as($admin)->deleteJson('/api/alerts/templates/GARANTIE_VIGILANCE')->assertOk();
        $this->assertStringContainsString('Nous vous rappelons que votre garantie', collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->firstWhere('id', $id)['text']);
        $this->assertDatabaseHas('audit_logs', ['entity_name' => 'AlertTemplate', 'entity_id' => 'GARANTIE_VIGILANCE']);
    }

    public function test_message_de_satisfaction_apres_le_renouvellement_d_une_garantie_relancee(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR']);
        $id = $this->guarantee();
        $jamais = $this->guarantee();
        $row = collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->firstWhere('id', $id);
        DB::table('alert_dispatches')->insert(['project_id' => $this->prise->id, 'category' => 'GARANTIE', 'alert_key' => $row['key'], 'subject' => 'x', 'recipients' => 'x', 'status' => 'ENVOYE', 'trigger' => 'AUTO', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$id, $jamais] as $g) {
            $end = substr(DB::table('performance_guarantees')->where('id', $g)->value('end_date'), 0, 10);
            $this->as($admin)->postJson("/api/guarantees/{$g}/renew", ['bank_name' => 'Equity BCDC', 'account_number' => "GBE-R{$g}", 'rate' => 5, 'start_date' => $end, 'end_date' => Dates::addDays($end, 365), 'reason' => 'Nouvelle garantie'])->assertOk();
        }
        $rows = collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->keyBy('id');
        $this->assertTrue($rows[$id]['satisfactionDue']);
        $this->assertFalse($rows[$jamais]['satisfactionDue']); // jamais relancée : pas de message de satisfaction
        $this->assertStringContainsString("Nous avons bien enregistré le renouvellement de votre garantie de bonne exécution n° GBE-R{$id}", $rows[$id]['satisfactionText']);

        $plan = collect(AlertEngine::run($this->prise->id, true))->where('category', 'GARANTIE')->values();
        $this->assertCount(1, $plan);
        $this->assertStringEndsWith('régularisation enregistrée', $plan[0]['subject']);

        DB::table('alert_dispatches')->insert(['project_id' => $this->prise->id, 'category' => 'GARANTIE', 'alert_key' => $rows[$id]['prefix'] . 'S', 'subject' => 'x', 'recipients' => 'x', 'status' => 'ENVOYE', 'trigger' => 'AUTO', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse(collect($this->as($admin)->getJson('/api/guarantees/alerts')->json('rows'))->firstWhere('id', $id)['satisfactionDue']);
    }

    public function test_message_de_retard_d_un_contrat_adresse_a_l_entreprise(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'can_view_alert_history' => true]);
        $today = Dates::today();
        $co = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'SZTC', 'email' => 'contact@sztc.test', 'created_at' => now(), 'updated_at' => now()]);
        $nomail = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'CGC', 'created_at' => now(), 'updated_at' => now()]);
        $make = fn ($company, $ref) => DB::table('contracts')->insertGetId(['project_id' => $this->prise->id, 'phase_id' => $this->phase->id, 'company_id' => $company, 'reference' => $ref, 'category' => 'TRAVAUX',
            'amount' => 1000, 'start_date' => Dates::addDays($today, -200), 'end_date' => Dates::addDays($today, -10), 'duration_months' => 6, 'created_at' => now(), 'updated_at' => now()]);
        $c = $make($co, '072/PRISE/2026');
        $d = $make($nomail, '073/PRISE/2026');

        $this->assertContains($c, array_column($this->as($admin)->getJson('/api/alerts')->assertOk()->json('retards'), 'id'));
        $p = $this->as($admin)->postJson('/api/alerts/send', ['type' => 'retard', 'id' => $c, 'preview' => true])->assertOk()->json();
        $this->assertSame(['contact@sztc.test'], $p['to']);
        $this->assertSame('[PRISE II] Contrat n° 072/PRISE/2026 — alerte de retard', $p['subject']);
        $this->assertStringContainsString("l'avancement physique est de 0,0 %", $p['text']);
        $this->as($admin)->postJson('/api/alerts/send', ['type' => 'retard', 'id' => $d])->assertStatus(422)
            ->assertJsonPath('message', 'L’entreprise CGC n’a pas d’adresse e-mail valide : complétez sa fiche dans Entreprises.');

        $this->as($admin)->postJson('/api/alerts/send', ['type' => 'retard', 'id' => $c])->assertStatus(422); // SMTP absent
        $h = $this->as($admin)->getJson("/api/alerts/history?contract_id={$c}")->json();
        $this->assertSame([1, 'CONTRAT', 'RETARD', '072/PRISE/2026'], [$h['total'], $h['data'][0]['scope'], $h['data'][0]['type'], $h['data'][0]['contractNumber']]);
    }

    /** Messages du personnel du projet prévus par le prochain envoi, par clé. */
    private function staffPlan(): array
    {
        $out = [];
        foreach (AlertEngine::run($this->prise->id, true) as $m) {
            if (str_starts_with($m['alerts'][0]['key'], 'PERSONNEL:')) $out[] = $m;
        }
        return $out;
    }

    public function test_rappel_hebdomadaire_du_personnel_jusqu_a_la_regularisation(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR', 'can_view_alert_history' => true]);
        foreach (['raf@example.test' => 'GARANTIE', 'suivi@example.test' => 'RETARD'] as $email => $cat) {
            DB::table('alert_recipients')->insert(['project_id' => $this->prise->id, 'email' => $email, 'categories' => $cat, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $id = $this->guarantee();
        $g = DB::table('performance_guarantees')->where('id', $id)->first();
        $contract = (int) $g->contract_id;

        // Un message distinct par destinataire : le personnel ne reçoit ni le texte de l'entreprise ni son adresse.
        $staff = $this->staffPlan();
        $this->assertCount(2, $staff);
        $this->assertSame(['GARANTIE', ['raf@example.test']], [$staff[0]['category'], $staff[0]['recipients']]);
        $this->assertSame(['RETARD', ['suivi@example.test']], [$staff[1]['category'], $staff[1]['recipients']]);
        $this->assertStringContainsString("garantie n° {$g->account_number}", $staff[0]['subject']);
        $this->assertStringContainsString('(vigilance)', $staff[0]['subject']);
        $this->assertStringContainsString('(retard)', $staff[1]['subject']);
        foreach (AlertEngine::run($this->prise->id, true) as $m) {
            if (in_array('direction@entreprise.test', $m['recipients'], true)) $this->assertSame(['direction@entreprise.test'], $m['recipients']);
        }

        // Le texte envoyé est celui du modèle du personnel, tracé dans l'historique.
        AlertEngine::run($this->prise->id, false);
        $h = $this->as($admin)->getJson("/api/alerts/history?contract_id={$contract}&type=PERSONNEL")->assertOk()->json();
        $this->assertSame(2, $h['total']);
        $this->assertStringStartsWith('Chers collègues,', $h['data'][0]['body']);
        $this->assertStringNotContainsString('Cher partenaire', $h['data'][0]['body']);

        // Une fois par semaine, sans plafond : rien avant 7 jours, de nouveau après, quel que soit le nombre d'envois.
        DB::table('alert_dispatches')->update(['status' => 'ENVOYE']);
        $this->assertCount(0, $this->staffPlan());
        DB::table('alert_dispatches')->update(['created_at' => now()->subDays(6)]);
        $this->assertCount(0, $this->staffPlan());
        DB::table('alert_dispatches')->update(['created_at' => now()->subDays(7)]);
        foreach (range(1, 5) as $i) {
            DB::table('alert_dispatches')->insert(['project_id' => $this->prise->id, 'category' => 'RETARD', 'alert_key' => "PERSONNEL:CONTRAT:{$contract}", 'subject' => 'x', 'recipients' => 'x', 'status' => 'ENVOYE', 'trigger' => 'AUTO', 'created_at' => now()->subDays(7 + $i), 'updated_at' => now()]);
        }
        $this->assertCount(2, $this->staffPlan());

        // Interrompre les relances d'une garantie ne vise que l'entreprise.
        $this->as($admin)->putJson("/api/guarantees/{$id}/alerts", ['paused' => true, 'reason' => 'Dossier en cours'])->assertOk();
        $this->assertCount(2, $this->staffPlan());

        // La prolongation arrête le rappel de la garantie ; le contrat reste suivi tant qu'il est en retard.
        $end = substr($g->end_date, 0, 10);
        $this->as($admin)->postJson("/api/guarantees/{$id}/extend", ['start_date' => $end, 'end_date' => Dates::addDays($end, 365), 'reason' => 'Avenant de prolongation'])->assertOk();
        $staff = $this->staffPlan();
        $this->assertCount(1, $staff);
        $this->assertSame('RETARD', $staff[0]['category']);

        // L'avancement physique s'améliore : le contrat sort du retard et le rappel s'arrête.
        $site = DB::table('contract_sites')->insertGetId(['contract_id' => $contract, 'province' => 'Kasaï-Central', 'name' => 'Site 1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('progress_entries')->insert(['contract_id' => $contract, 'contract_site_id' => $site, 'situation_date' => Dates::today(), 'progress' => 85, 'paid_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertCount(0, $this->staffPlan());
    }

    public function test_arret_general_et_categorie_desactivee_coupent_le_rappel_du_personnel(): void
    {
        DB::table('alert_recipients')->insert(['project_id' => $this->prise->id, 'email' => 'raf@example.test', 'categories' => 'GARANTIE,RETARD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->guarantee();
        $this->assertCount(2, $this->staffPlan());
        DB::table('alert_rules')->insert(['project_id' => $this->prise->id, 'category' => 'GARANTIE', 'is_enabled' => false, 'interval_days' => 7, 'max_sends' => 3, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('alert_rules')->insert(['project_id' => $this->prise->id, 'category' => 'RETARD', 'is_enabled' => false, 'interval_days' => 7, 'max_sends' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertCount(0, $this->staffPlan());
    }

    public function test_ajouter_et_supprimer_un_destinataire_du_projet_dans_les_parametres(): void
    {
        $admin = $this->user(['role' => 'ADMINISTRATEUR']);
        $antenne = $this->user(['role' => 'ADMINISTRATEUR', 'organizational_unit_id' => $this->pool->id]);
        $agent = $this->user();

        $this->as($agent)->postJson('/api/settings/recipients', ['email' => 'nouveau@example.test'])->assertForbidden();
        $this->as($antenne)->postJson('/api/settings/recipients', ['email' => 'nouveau@example.test'])->assertForbidden();
        $this->as($antenne)->getJson('/api/settings')->assertOk()->assertJsonPath('canEditRecipients', false);

        $poste = DB::table('positions')->insertGetId(['project_id' => $this->prise->id, 'organizational_unit_id' => $this->cn->id, 'name' => 'HRAF', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $autreProjet = Project::create(['code' => 'PREDIRE', 'name' => 'PREDIRE']);
        $etranger = DB::table('positions')->insertGetId(['project_id' => $autreProjet->id, 'name' => 'HRAF', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $form = ['email' => ' Nouveau@Example.test ', 'name' => 'Jeanne KABEYA', 'position_id' => $poste];

        // Adresse, nom et poste sont tous obligatoires ; le poste vient de la liste du projet.
        $this->as($admin)->postJson('/api/settings/recipients', ['email' => 'pas-une-adresse'] + $form)->assertStatus(422)->assertJsonValidationErrors('email');
        $this->as($admin)->postJson('/api/settings/recipients', ['email' => 'a@example.test'])->assertStatus(422)->assertJsonValidationErrors(['name', 'position_id']);
        $this->as($admin)->postJson('/api/settings/recipients', ['position_id' => $etranger] + $form)->assertStatus(422)->assertJsonValidationErrors('position_id');
        $this->as($admin)->postJson('/api/settings/recipients', $form)->assertOk();
        $this->as($admin)->postJson('/api/settings/recipients', ['email' => 'nouveau@example.test'] + $form)->assertStatus(422)->assertJsonValidationErrors('email');

        $s = $this->as($admin)->getJson('/api/settings')->assertOk()->assertJsonPath('canEditRecipients', true)->json();
        $this->assertSame(['nouveau@example.test', 'Jeanne KABEYA', 'HRAF'], [$s['recipients'][0]['email'], $s['recipients'][0]['name'], $s['recipients'][0]['position']]);
        $this->assertContains(['id' => $poste, 'name' => 'HRAF', 'unit' => 'Coordination nationale'], $s['positions']);
        $this->assertSame(['RETARD', 'GARANTIE'], explode(',', DB::table('alert_recipients')->value('categories')));

        // Un destinataire enregistré sans poste (ancienne liste) se complète par la modification.
        $sansPoste = DB::table('alert_recipients')->insertGetId(['project_id' => $this->prise->id, 'email' => 'ancien@example.test', 'categories' => 'RETARD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->as($antenne)->putJson("/api/settings/recipients/{$sansPoste}", ['email' => 'ancien@example.test', 'name' => 'Ancien', 'position_id' => $poste])->assertForbidden();
        $this->as($admin)->putJson("/api/settings/recipients/{$sansPoste}", ['email' => 'ancien@example.test', 'name' => 'Ancien', 'position_id' => $poste])->assertOk();
        $this->assertSame('RETARD', DB::table('alert_recipients')->where('id', $sansPoste)->value('categories')); // ses catégories ne changent pas
        $this->assertEquals($poste, DB::table('alert_recipients')->where('id', $sansPoste)->value('position_id'));
        $this->as($admin)->deleteJson("/api/settings/recipients/{$sansPoste}")->assertOk();

        // Il reçoit le rappel du personnel ; une fois supprimé, il ne reçoit plus rien.
        $this->guarantee();
        $this->assertSame(['nouveau@example.test'], $this->staffPlan()[0]['recipients']);
        $this->as($admin)->deleteJson('/api/settings/recipients/' . $s['recipients'][0]['id'])->assertOk();
        $this->assertSame([], $this->staffPlan());
        $this->assertDatabaseHas('audit_logs', ['entity_name' => 'AlertRecipient', 'action' => 'SUPPRESSION']);

        // Les paramètres SMTP s'enregistrent sans les anciens champs « Destinataire ».
        $this->as($admin)->putJson('/api/settings/smtp', ['host' => 'smtp.example.test', 'port' => 587, 'sender_email' => 'envoi@example.test'])->assertOk();
        $this->assertFalse(array_key_exists('recipient1', $this->as($admin)->getJson('/api/settings')->json('smtp')));
    }

    /** Messages des contrats à l'entreprise prévus par le prochain envoi. */
    private function contractPlan(): array
    {
        return array_values(array_filter(AlertEngine::run($this->prise->id, true), fn ($m) => $m['category'] === 'CONTRAT'));
    }

    public function test_alertes_de_contrat_automatiques_chez_le_partenaire(): void
    {
        $today = Dates::today();
        $co = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'SZTC', 'email' => 'contact@sztc.test', 'created_at' => now(), 'updated_at' => now()]);
        $nomail = DB::table('companies')->insertGetId(['project_id' => $this->prise->id, 'name' => 'CGC', 'created_at' => now(), 'updated_at' => now()]);
        $make = fn ($company, $ref, $from, $to) => DB::table('contracts')->insertGetId(['project_id' => $this->prise->id, 'phase_id' => $this->phase->id, 'company_id' => $company, 'reference' => $ref, 'category' => 'TRAVAUX',
            'amount' => 1000, 'start_date' => Dates::addDays($today, $from), 'end_date' => Dates::addDays($today, $to), 'duration_months' => 6, 'created_at' => now(), 'updated_at' => now()]);
        $progress = function (int $c, float $v) use ($today) {
            $site = DB::table('contract_sites')->where('contract_id', $c)->value('id') ?: DB::table('contract_sites')->insertGetId(['contract_id' => $c, 'province' => 'Kasaï-Central', 'name' => 'Site', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('progress_entries')->insert(['contract_id' => $c, 'contract_site_id' => $site, 'situation_date' => $today, 'progress' => $v, 'paid_amount' => 0, 'created_at' => now(), 'updated_at' => now()]);
        };
        $late = $make($co, '072/PRISE', -200, -10);
        $make($nomail, '073/PRISE', -200, -10);
        DB::table('alert_recipients')->insert(['project_id' => $this->prise->id, 'email' => 'raf@example.test', 'categories' => 'RETARD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        // Le message part à la seule entreprise ; sans adresse, rien ne part et le plan le signale.
        $plan = collect($this->contractPlan())->keyBy(fn ($m) => $m['alerts'][0]['key']);
        $this->assertSame(['contact@sztc.test'], $plan["CONTRAT:{$late}:RETARD"]['recipients']);
        $this->assertSame('[PRISE II] Contrat n° 072/PRISE — alerte de retard', $plan["CONTRAT:{$late}:RETARD"]['subject']);
        $this->assertCount(1, $plan->where('status', 'SANS_ADRESSE'));

        // Sans SMTP : échec tracé, non consommé. Une fois envoyé : pas avant 7 jours, puis de nouveau, sans plafond.
        AlertEngine::run($this->prise->id, false);
        $line = DB::table('alert_dispatches')->where('alert_key', "CONTRAT:{$late}:RETARD")->first();
        $this->assertSame(['ECHEC', 'RETARD', 'contact@sztc.test', $late], [$line->status, $line->message_type, $line->recipients, (int) $line->contract_id]);
        $this->assertCount(2, $this->contractPlan());
        DB::table('alert_dispatches')->where('id', $line->id)->update(['status' => 'ENVOYE']);
        $this->assertCount(1, $this->contractPlan());
        DB::table('alert_dispatches')->where('id', $line->id)->update(['created_at' => now()->subDays(7)]);
        foreach (range(1, 4) as $i) DB::table('alert_dispatches')->insert(['project_id' => $this->prise->id, 'category' => 'CONTRAT', 'alert_key' => "CONTRAT:{$late}:RETARD", 'subject' => 'x', 'recipients' => 'x', 'status' => 'ENVOYE', 'trigger' => 'AUTO', 'created_at' => now()->subDays(7 + $i), 'updated_at' => now()]);
        $this->assertCount(2, $this->contractPlan());

        // Vigilance : autre clé, envoi immédiat. Écart de −22 points sur un contrat dont la fin n'est pas dépassée.
        $watch = $make($co, '074/PRISE', -50, 50);
        $progress($watch, 28);
        $keys = array_map(fn ($m) => $m['alerts'][0]['key'], $this->contractPlan());
        $this->assertContains("CONTRAT:{$watch}:VIGILANCE", $keys);

        // L'avancement s'améliore : plus d'alerte de retard. Terminé : un seul message de satisfaction.
        $progress($late, 100);
        $plan = collect($this->contractPlan())->keyBy(fn ($m) => $m['alerts'][0]['key']);
        $this->assertFalse($plan->has("CONTRAT:{$late}:RETARD"));
        $this->assertSame('[PRISE II] Contrat n° 072/PRISE — exécution achevée', $plan["CONTRAT:{$late}:SATISFACTION"]['subject']);
        DB::table('alert_dispatches')->insert(['project_id' => $this->prise->id, 'category' => 'CONTRAT', 'alert_key' => "CONTRAT:{$late}:SATISFACTION", 'subject' => 'x', 'recipients' => 'x', 'status' => 'ENVOYE', 'trigger' => 'AUTO', 'created_at' => now()->subDays(30), 'updated_at' => now()]);
        $this->assertFalse(collect($this->contractPlan())->contains(fn ($m) => $m['alerts'][0]['key'] === "CONTRAT:{$late}:SATISFACTION"));

        // Un contrat résilié ne reçoit plus rien ; la catégorie désactivée coupe tout.
        DB::table('contracts')->where('id', $watch)->update(['termination_date' => $today, 'termination_reason' => 'Motif de test']);
        $this->assertFalse(collect($this->contractPlan())->contains(fn ($m) => $m['alerts'][0]['key'] === "CONTRAT:{$watch}:VIGILANCE"));
        DB::table('alert_rules')->insert(['project_id' => $this->prise->id, 'category' => 'RETARD', 'is_enabled' => false, 'interval_days' => 7, 'max_sends' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], $this->contractPlan());
    }

    public function test_fuseau_horaire_de_kinshasa(): void
    {
        $this->assertSame('Africa/Kinshasa', config('app.timezone'));
        $this->assertSame('Africa/Kinshasa', date_default_timezone_get());
        $this->assertSame('+01:00', now()->format('P'));
    }
}
