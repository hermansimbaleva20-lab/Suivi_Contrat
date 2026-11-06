<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Contrôle de la configuration avant une mise en ligne : chaque point en échec expose l'application. Code de sortie 1 tant
 * qu'un point échoue, pour pouvoir bloquer un déploiement automatique.
 */
class VerifySecurity extends Command
{
    protected $signature = 'prise:verifier-securite';

    protected $description = 'Vérifie que la configuration est sûre pour une mise en ligne (mode production, HTTPS, base, CORS, clé).';

    public function handle(): int
    {
        $db = config('database.connections.'.config('database.default'), []);
        $origins = (array) config('cors.allowed_origins', []);
        $level = (string) config('logging.channels.'.config('logging.default').'.level', 'debug');
        $checks = [
            ['APP_ENV=production', config('app.env') === 'production', 'Sinon Laravel se comporte en développement.'],
            ['APP_DEBUG=false', ! config('app.debug'), 'En mode débogage, chaque erreur renvoie le fichier, la ligne et la pile d’appels du serveur.'],
            ['APP_KEY définie', strlen((string) config('app.key')) >= 32, 'Elle chiffre le mot de passe SMTP : générez-la avec « php artisan key:generate ».'],
            ['APP_URL en HTTPS', str_starts_with((string) config('app.url'), 'https://'), 'En HTTP, les jetons de session et les mots de passe circulent en clair.'],
            ['FRONTEND_URL en HTTPS', str_starts_with((string) config('prise.frontend_url'), 'https://'), 'Les liens des e-mails (réinitialisation du mot de passe) doivent être en HTTPS.'],
            ['Base : compte dédié, pas root', ($db['username'] ?? '') !== '' && strtolower((string) $db['username']) !== 'root', 'Créez un compte MySQL limité à la base de l’application.'],
            ['Base : mot de passe défini', (string) ($db['password'] ?? '') !== '', 'Un compte MySQL sans mot de passe est ouvert à tout processus de la machine.'],
            ['CORS : origines en HTTPS uniquement', $origins !== [] && collect($origins)->every(fn ($o) => str_starts_with($o, 'https://')), 'CORS_ALLOWED_ORIGINS (ou FRONTEND_URL) doit désigner le frontend en HTTPS.'],
            ['Journal : niveau warning ou plus', ! in_array(strtolower($level), ['debug', 'info', 'notice'], true), 'LOG_LEVEL=debug écrit dans le journal le détail des requêtes.'],
        ];
        $failed = 0;
        foreach ($checks as [$label, $ok, $why]) {
            $this->line(($ok ? '<info>  OK   </info> ' : '<error> ÉCHEC </error> ').$label.($ok ? '' : " — {$why}"));
            if (! $ok) {
                $failed++;
            }
        }
        if ($failed) {
            $this->newLine();
            $this->error("{$failed} point(s) à corriger avant la mise en ligne (voir docs/DEPLOIEMENT_SECURISE.md).");

            return self::FAILURE;
        }
        $this->info('Configuration prête pour la mise en ligne.');

        return self::SUCCESS;
    }
}
