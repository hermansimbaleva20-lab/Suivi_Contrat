<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité de toutes les réponses de l'API : elle ne renvoie que du JSON et des fichiers, jamais une page à afficher.
 * Une réponse ne peut donc ni exécuter de script, ni être affichée dans le cadre d'un autre site, ni être réinterprétée par le navigateur.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }
        $h = $response->headers;
        $h->remove('X-Powered-By');
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        if (! $h->has('Content-Security-Policy')) {
            $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
