<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Alertes par e-mail (TDR §10) : chaque jour à 7 h (heure de Kinshasa), les alertes dues sont envoyées selon la séquence
 * réglée par le super-administrateur. Le serveur doit lancer « php artisan schedule:run » chaque minute
 * (cron sous Linux, Planificateur de tâches sous Windows).
 */
Schedule::command('prise:send-alerts')->dailyAt('07:00')->timezone('Africa/Kinshasa')->withoutOverlapping();
