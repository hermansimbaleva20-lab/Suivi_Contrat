<?php

namespace App\Console\Commands;

use App\Support\AlertEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Envoi planifié des alertes par e-mail (planifié chaque jour dans routes/console.php). */
class SendAlerts extends Command
{
    protected $signature = 'prise:send-alerts {--project= : code du projet (PRISE ou PREDIRE) ; tous par défaut} {--dry-run : affiche ce qui serait envoyé, sans rien envoyer}';
    protected $description = 'Envoie les alertes dues aux destinataires configurés par le super-administrateur, selon la séquence de chaque catégorie.';

    public function handle(): int
    {
        $q = DB::table('projects');
        if ($this->option('project')) $q->where('code', strtoupper($this->option('project')));
        foreach ($q->get() as $p) {
            $plan = AlertEngine::run((int) $p->id, (bool) $this->option('dry-run'));
            $this->info("{$p->code} : " . count($plan) . ' message(s).');
            foreach ($plan as $m) $this->line("  [{$m['status']}] {$m['subject']} → " . implode(', ', $m['recipients']) . ($m['message'] ? " — {$m['message']}" : ''));
        }
        return self::SUCCESS;
    }
}
