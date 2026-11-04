<?php

namespace App\Http\Controllers;

use App\Support\AiService;
use App\Support\Ctx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * IA (TDR §10.3) : réglages réservés au super-admin (fournisseur, modèle, clé chiffrée, fonctions autorisées) et trois
 * fonctions : commentaire de rapport, synthèse des alertes (dans AlertEngine), repérage de l'entreprise et des contacts
 * dans le texte d'un contrat. Les données envoyées au fournisseur sont limitées à ce que la fonction exige.
 */
class AiController extends Controller
{
    private function needSuperAdmin(): void
    {
        abort_unless((bool) Ctx::user()->is_super_admin, 403, 'L’IA est réglée par le super-administrateur.');
    }

    private function needFeature(string $feature): void
    {
        abort_unless(AiService::enabled(Ctx::projectId(), $feature), 403, 'Cette fonction d’IA n’est pas autorisée par le super-administrateur.');
    }

    /** Fonctions disponibles pour l'utilisateur courant (affichage des boutons). */
    public function status()
    {
        $pid = Ctx::projectId();
        return collect(array_keys(AiService::FEATURES))->mapWithKeys(fn ($f) => [$f => AiService::enabled($pid, $f)]);
    }

    public function settings()
    {
        $this->needSuperAdmin();
        $s = AiService::settings(Ctx::projectId());
        return [
            'isEnabled' => (bool) ($s->is_enabled ?? false), 'provider' => $s->provider ?? 'ANTHROPIC', 'model' => $s->model ?? AiService::DEFAULT_MODEL,
            'endpoint' => (string) ($s->endpoint ?? ''), 'hasKey' => !empty($s->api_key ?? null),
            'features' => ['comments' => (bool) ($s->feature_comments ?? false), 'alerts' => (bool) ($s->feature_alerts ?? false), 'extraction' => (bool) ($s->feature_extraction ?? false)],
        ];
    }

    public function update(Request $r)
    {
        $this->needSuperAdmin();
        $d = $r->validate([
            'is_enabled' => 'required|boolean',
            'provider' => ['required', Rule::in(['ANTHROPIC', 'OPENAI_COMPATIBLE'])],
            'model' => 'required|string|max:100',
            'endpoint' => 'nullable|url:https,http|max:255|required_if:provider,OPENAI_COMPATIBLE',
            'api_key' => 'nullable|string|max:500',
            'features' => 'required|array',
            'features.comments' => 'boolean', 'features.alerts' => 'boolean', 'features.extraction' => 'boolean',
        ], ['endpoint.required_if' => 'Indiquez l’adresse du service compatible OpenAI.'], ['endpoint' => 'adresse du service', 'model' => 'modèle']);
        $pid = Ctx::projectId();
        $before = (array) (AiService::settings($pid) ?? []);
        unset($before['api_key']);
        $row = [
            'is_enabled' => $d['is_enabled'], 'provider' => $d['provider'], 'model' => trim($d['model']), 'endpoint' => isset($d['endpoint']) ? trim($d['endpoint']) : null,
            'feature_comments' => (bool) ($d['features']['comments'] ?? false), 'feature_alerts' => (bool) ($d['features']['alerts'] ?? false),
            'feature_extraction' => (bool) ($d['features']['extraction'] ?? false), 'updated_by' => Ctx::user()->id, 'updated_at' => now(),
        ];
        // La clé n'est jamais renvoyée ; un champ vide conserve la clé enregistrée.
        if (!empty($d['api_key'])) $row['api_key'] = Crypt::encryptString(trim($d['api_key']));
        abort_if($d['is_enabled'] && empty($row['api_key']) && empty(AiService::settings($pid)?->api_key), 422, 'Enregistrez la clé d’API du fournisseur avant d’activer l’IA.');
        DB::table('ai_settings')->updateOrInsert(['project_id' => $pid], $row + ['created_at' => now()]);
        $after = $row;
        unset($after['api_key']);
        Ctx::auditChange('MODIFICATION', 'AiSettings', $pid, 'Paramètres de l’IA modifiés' . (!empty($d['api_key']) ? ' (nouvelle clé d’API enregistrée).' : '.'), $before, $after);
        return ['message' => $d['is_enabled'] ? 'Paramètres de l’IA enregistrés : l’IA est active pour les fonctions cochées.' : 'Paramètres enregistrés : l’IA est désactivée.'];
    }

    public function test()
    {
        $this->needSuperAdmin();
        $res = AiService::complete(Ctx::projectId(), 'Tu réponds en une phrase courte, en français.', 'Confirme que la connexion fonctionne.', 200);
        abort_unless($res['ok'], 422, $res['message']);
        return ['message' => 'Connexion réussie. Réponse du service : « ' . mb_substr($res['text'], 0, 200) . ' »'];
    }

    /** Commentaire narratif d'une période de rapport (mêmes paramètres que l'aperçu des états). */
    public function reportComment(Request $r)
    {
        $this->needFeature('comments');
        $p = app(ReportSnapshotController::class)->preview($r);
        $rows = array_map(fn ($x) => "- {$x['number']} ({$x['company']}) : exécution {$x['progress']} %, délai consommé {$x['elapsed']} %, statut {$x['status']}, taux de paiement {$x['paymentRate']} %"
            . ($x['observation'] ? ", observation : {$x['observation']}" : ''), array_slice($p['rows'], 0, 80));
        $s = $p['summary'];
        $f = $p['fuel'];
        $prompt = "Période : {$p['label']} (du {$p['start']} au {$p['end']}).\nSynthèse : {$s['contracts']} contrats, exécution physique moyenne {$s['progress']} %, taux de paiement global {$s['paymentRate']} %."
            . "\nCarburant (saisies autorisées) : véhicules {$f['vehicles']['distance']} km et {$f['vehicles']['litresPurchased']} L ; groupes {$f['generators']['distance']} h et {$f['generators']['litresPurchased']} L."
            . "\nContrats :\n" . implode("\n", $rows);
        $res = AiService::complete(Ctx::projectId(),
            'Tu es chargé du suivi-évaluation d’un projet d’infrastructures financé par la BAD en RDC. Rédige en français, en 120 à 200 mots, le commentaire de la période : '
            . 'situation d’ensemble, contrats en retard ou à risque (cite leur numéro), avancement des paiements, carburant si pertinent, recommandations. '
            . 'Ton factuel et professionnel, sans titre ni liste à puces, uniquement à partir des données fournies.', $prompt, 2000);
        abort_unless($res['ok'], 422, $res['message']);
        Ctx::audit('IA_COMMENTAIRE', 'ReportSnapshot', $p['label'], "Commentaire de la période {$p['label']} rédigé par l’IA (à relire avant de figer l’état).");
        return ['comment' => mb_substr($res['text'], 0, 2000)];
    }

    /** Repérage de l'entreprise, du contrat et des contacts dans le texte d'un contrat, et rapprochement avec les entreprises du projet. */
    public function extract(Request $r)
    {
        $this->needFeature('extraction');
        $d = $r->validate(['text' => 'required|string|min:50|max:30000'], ['text.min' => 'Collez au moins quelques lignes du contrat.', 'text.max' => 'Le texte dépasse 30 000 caractères : collez la première page et la page des signatures.'], ['text' => 'texte du contrat']);
        $res = AiService::complete(Ctx::projectId(),
            'Tu extrais des informations d’un contrat de marché public. Réponds uniquement par un objet JSON avec les clés : entreprise (raison sociale du titulaire), '
            . 'numero_contrat, objet, montant (nombre), devise, date_signature (AAAA-MM-JJ), representant, emails (liste), telephones (liste). Mets null pour une information absente ; n’invente rien.',
            $d['text'], 2000);
        abort_unless($res['ok'], 422, $res['message']);
        $data = AiService::json($res['text']);
        abort_unless($data, 422, 'La réponse de l’IA n’a pas pu être lue. Réessayez avec un extrait plus court.');
        $emails = array_values(array_filter((array) ($data['emails'] ?? []), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $name = trim((string) ($data['entreprise'] ?? ''));
        $match = null;
        if ($name !== '') {
            $companies = DB::table('companies')->where('project_id', Ctx::projectId())->get(['id', 'name', 'email', 'phone']);
            $norm = fn ($s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s));
            foreach ($companies as $c) {
                if ($norm($c->name) === $norm($name) || str_contains($norm($c->name), $norm($name)) || str_contains($norm($name), $norm($c->name))) { $match = $c; break; }
            }
        }
        Ctx::audit('IA_EXTRACTION', 'Contract', '', "Informations extraites d’un texte de contrat par l’IA" . ($name ? " (entreprise : {$name})." : '.'));
        return [
            'fields' => ['company' => $name ?: null, 'contractNumber' => $data['numero_contrat'] ?? null, 'subject' => $data['objet'] ?? null, 'amount' => is_numeric($data['montant'] ?? null) ? (float) $data['montant'] : null,
                'currency' => $data['devise'] ?? null, 'signatureDate' => $data['date_signature'] ?? null, 'representative' => $data['representant'] ?? null,
                'emails' => $emails, 'phones' => array_values(array_filter((array) ($data['telephones'] ?? [])))],
            'match' => $match ? ['id' => $match->id, 'name' => $match->name, 'email' => $match->email, 'phone' => $match->phone] : null,
        ];
    }

    /** Complète l'adresse et le téléphone de l'entreprise rapprochée, seulement s'ils sont vides (pour les notifications d'alerte). */
    public function applyExtraction(Request $r)
    {
        Ctx::need('can_manage_companies');
        $d = $r->validate(['company_id' => ['required', 'integer', Rule::exists('companies', 'id')->where('project_id', Ctx::projectId())], 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:60']);
        $c = DB::table('companies')->where('id', $d['company_id'])->first();
        $set = [];
        if (!empty($d['email']) && trim((string) $c->email) === '') $set['email'] = $d['email'];
        if (!empty($d['phone']) && trim((string) $c->phone) === '') $set['phone'] = $d['phone'];
        abort_if(!$set, 422, 'L’adresse et le téléphone de cette entreprise sont déjà renseignés : modifiez-les dans Entreprises si besoin.');
        DB::table('companies')->where('id', $c->id)->update($set + ['updated_at' => now()]);
        Ctx::auditChange('MODIFICATION', 'Company', $c->id, "Coordonnées de {$c->name} complétées à partir du contrat (extraction par l’IA).", ['email' => $c->email, 'phone' => $c->phone], $set);
        return ['message' => "Coordonnées de {$c->name} complétées."];
    }
}
