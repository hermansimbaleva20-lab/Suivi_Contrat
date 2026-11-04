<?php

return [
    // Adresse publique du site React (utilisée dans les liens des e-mails de réinitialisation du mot de passe).
    'frontend_url' => rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost:5173')), '/'),
    // Adresse du super-administrateur (créé/promu par la migration et par « php artisan prise:create-super-admin »).
    'super_admin_email' => env('SUPER_ADMIN_EMAIL', 'hermansimbaleva20@gmail.com'),
    'reset_token_minutes' => 30,
    'max_failed_logins' => 5,
    'lockout_minutes' => 15,
];
