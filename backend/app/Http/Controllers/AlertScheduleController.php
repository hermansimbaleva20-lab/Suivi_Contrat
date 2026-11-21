<?php

namespace App\Http\Controllers;

use App\Support\AlertSchedule;
use Illuminate\Http\Request;

/** Envoi des alertes dues sans tâche planifiée : activité des comptes connectés, ou cron web muni du secret CRON_SECRET. */
class AlertScheduleController extends Controller
{
    /** Appelé discrètement par l'application à son ouverture, au plus une fois par heure et par navigateur. */
    public function auto()
    {
        return response()->json(AlertSchedule::runDue());
    }

    /** Cron web : secret dans l'en-tête X-Cron-Secret ou le paramètre « cle ». 404 sans secret configuré ou s'il est faux. */
    public function cron(Request $r)
    {
        $secret = (string) config('prise.cron_secret');
        $given = (string) ($r->header('X-Cron-Secret') ?: $r->query('cle', ''));
        abort_if(strlen($secret) < 32 || ! hash_equals($secret, $given), 404);

        return response()->json(AlertSchedule::runDue());
    }
}
