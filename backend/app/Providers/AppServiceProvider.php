<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
        // Derrière HTTPS, les liens générés (e-mails, téléchargements) restent en HTTPS.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
        $this->rateLimits();
    }

    /**
     * Limites de débit par adresse IP des routes publiques (essais de mots de passe, inscriptions et e-mails en rafale), et par
     * compte de l'API. Le blocage d'un compte après 5 échecs (config/prise.php) reste en place en plus.
     */
    private function rateLimits(): void
    {
        $tooMany = fn (string $message) => fn (Request $request, array $headers) => response()->json(['message' => $message], 429, $headers);
        $wait = 'Trop de tentatives depuis cette connexion. Réessayez dans quelques minutes.';

        RateLimiter::for('connexion', fn (Request $r) => Limit::perMinutes(10, 30)->by('connexion|'.$r->ip())->response($tooMany($wait)));
        RateLimiter::for('inscription', fn (Request $r) => Limit::perHour(10)->by('inscription|'.$r->ip())->response($tooMany($wait)));
        RateLimiter::for('mot-de-passe-oublie', fn (Request $r) => [
            Limit::perMinutes(15, 5)->by('oubli|'.$r->ip())->response($tooMany($wait)),
            Limit::perHour(5)->by('oubli-compte|'.strtolower(trim((string) $r->input('email'))))->response($tooMany($wait)),
        ]);
        RateLimiter::for('lien-mot-de-passe', fn (Request $r) => Limit::perMinutes(10, 30)->by('lien|'.$r->ip())->response($tooMany($wait)));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(600)->by('api|'.($r->user()?->id ?: $r->ip()))
            ->response($tooMany('Trop de requêtes en peu de temps. Patientez une minute.')));
    }
}
