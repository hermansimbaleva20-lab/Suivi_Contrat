<?php

/*
 * Origines autorisées à appeler l'API depuis un navigateur : le frontend seulement. CORS_ALLOWED_ORIGINS (séparées par des
 * virgules), à défaut FRONTEND_URL. En local, les serveurs Vite de développement s'y ajoutent. Jamais « * ».
 * L'API s'authentifie par jeton (en-tête Authorization), pas par cookie : supports_credentials reste à false.
 */
$origins = array_map(fn ($o) => rtrim(trim($o), '/'), explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173'))));
if (env('APP_ENV') === 'local') {
    $origins = [...$origins, 'http://localhost:5173', 'http://127.0.0.1:5173', 'http://localhost:5174', 'http://127.0.0.1:5174'];
}

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_unique(array_filter($origins, fn ($o) => $o !== '' && $o !== '*'))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Project', 'X-Phase', 'X-Requested-With'],
    // Nom du fichier des exports et documents, lu par api.js.
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 3600,
    'supports_credentials' => false,
];
