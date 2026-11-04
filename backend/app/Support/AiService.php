<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;


class AiService
{
    public const FEATURES = ['comments' => 'feature_comments', 'alerts' => 'feature_alerts', 'extraction' => 'feature_extraction'];
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    public static function settings(int $projectId): ?object
    {
        return DB::table('ai_settings')->where('project_id', $projectId)->first();
    }

    /** L'IA est-elle autorisée pour cette fonction (comments | alerts | extraction) ? */
    public static function enabled(int $projectId, string $feature): bool
    {
        $s = self::settings($projectId);
        return $s && $s->is_enabled && !empty($s->api_key) && !empty($s->{self::FEATURES[$feature]});
    }

    /** @return array{ok: bool, text: string, message: string} */
    public static function complete(int $projectId, string $system, string $prompt, int $maxTokens = 16000): array
    {
        $s = self::settings($projectId);
        if (!$s || !$s->is_enabled || empty($s->api_key)) return ['ok' => false, 'text' => '', 'message' => 'L’IA n’est pas activée pour ce projet.'];
        try {
            $key = Crypt::decryptString($s->api_key);
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => '', 'message' => 'Clé d’API illisible : enregistrez-la de nouveau.'];
        }
        try {
            return $s->provider === 'OPENAI_COMPATIBLE'
                ? self::openAiCompatible($s, $key, $system, $prompt, $maxTokens)
                : self::anthropic($s, $key, $system, $prompt, $maxTokens);
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => '', 'message' => 'Service d’IA injoignable : ' . mb_substr($e->getMessage(), 0, 200)];
        }
    }

    private static function anthropic(object $s, string $key, string $system, string $prompt, int $maxTokens): array
    {
        $res = Http::timeout(120)->withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => '2023-06-01',
            // Bascule automatique vers un autre modèle si le modèle choisi décline la demande.
            'anthropic-beta' => 'server-side-fallback-2026-07-01',
        ])->post(rtrim($s->endpoint ?: 'https://api.anthropic.com', '/') . '/v1/messages', [
            'model' => $s->model ?: self::DEFAULT_MODEL,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'fallbacks' => 'default',
        ]);
        if (!$res->successful()) return ['ok' => false, 'text' => '', 'message' => 'Le service d’IA a répondu ' . $res->status() . ' : ' . mb_substr((string) ($res->json('error.message') ?? $res->body()), 0, 300)];
        if ($res->json('stop_reason') === 'refusal') return ['ok' => false, 'text' => '', 'message' => 'Le service d’IA a décliné cette demande.'];
        // Seuls les blocs de texte forment la réponse (les blocs de réflexion éventuels sont ignorés).
        $text = collect($res->json('content') ?? [])->where('type', 'text')->pluck('text')->implode("\n");
        return ['ok' => trim($text) !== '', 'text' => trim($text), 'message' => trim($text) === '' ? 'Réponse vide du service d’IA.' : ''];
    }

    private static function openAiCompatible(object $s, string $key, string $system, string $prompt, int $maxTokens): array
    {
        abort_unless($s->endpoint, 422, 'Indiquez l’adresse du service compatible OpenAI.');
        $res = Http::timeout(120)->withToken($key)->post(rtrim($s->endpoint, '/') . '/chat/completions', [
            'model' => $s->model,
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
        ]);
        if (!$res->successful()) return ['ok' => false, 'text' => '', 'message' => 'Le service d’IA a répondu ' . $res->status() . ' : ' . mb_substr((string) ($res->json('error.message') ?? $res->body()), 0, 300)];
        $text = trim((string) $res->json('choices.0.message.content'));
        return ['ok' => $text !== '', 'text' => $text, 'message' => $text === '' ? 'Réponse vide du service d’IA.' : ''];
    }

    /** Premier objet JSON contenu dans une réponse (le modèle peut l'entourer de texte ou d'un bloc ```json). */
    public static function json(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }
}
