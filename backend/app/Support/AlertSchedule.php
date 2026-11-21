<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Envoi des alertes dues sans tâche planifiée (décision du 13/11/2026 : hébergement sans cron, InfinityFree). Déclenché par
 * l'activité des comptes connectés (POST /alerts/auto) ou par une adresse secrète (GET /cron/alertes). Même règle que la
 * tâche Windows : à partir de 7 h, heure de Kinshasa, au plus une fois par heure ; AlertEngine n'envoie que ce qui est dû,
 * sans doublon, et un verrou empêche deux envois simultanés.
 */
class AlertSchedule
{
    public const START_HOUR = 7;

    /** @return array{ran:bool, reason:string, messages:int} */
    public static function runDue(): array
    {
        $now = Carbon::now(config('app.timezone'));
        if ($now->hour < self::START_HOUR) {
            return ['ran' => false, 'reason' => 'AVANT_7H', 'messages' => 0];
        }
        $lock = Cache::lock('prise:alertes:envoi', 900);
        if (! $lock->get()) {
            return ['ran' => false, 'reason' => 'EN_COURS', 'messages' => 0];
        }
        try {
            // Une seule exécution par heure, même si plusieurs comptes ouvrent l'application.
            if (! Cache::add('prise:alertes:'.$now->format('Y-m-d-H'), true, 7200)) {
                return ['ran' => false, 'reason' => 'DEJA_FAIT', 'messages' => 0];
            }
            // L'envoi continue si le navigateur quitte la page ; certains hébergeurs désactivent ces fonctions.
            if (function_exists('ignore_user_abort')) {
                ignore_user_abort(true);
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            $messages = 0;
            foreach (DB::table('projects')->orderBy('id')->pluck('id') as $projectId) {
                $messages += count(AlertEngine::run((int) $projectId, false));
            }

            return ['ran' => true, 'reason' => 'ENVOYE', 'messages' => $messages];
        } catch (\Throwable $e) {
            Log::error('Envoi automatique des alertes : '.$e->getMessage());

            return ['ran' => false, 'reason' => 'ERREUR', 'messages' => 0];
        } finally {
            $lock->release();
        }
    }
}
