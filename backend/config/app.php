<?php

/*
 * Complète la configuration par défaut de Laravel, qui fixe le fuseau à UTC sans lire APP_TIMEZONE.
 * Toutes les heures de l'application (saisies, audit, envois, date du jour de Dates::today) sont celles de Kinshasa.
 */
return [
    'timezone' => env('APP_TIMEZONE', 'Africa/Kinshasa'),
];
