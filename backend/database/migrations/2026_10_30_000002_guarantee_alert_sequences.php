<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relances des garanties de bonne exécution adressées à l'entreprise (App\Support\GuaranteeAlerts) :
 *  - performance_guarantees : suspension par un administrateur (date, auteur, motif) et fréquence propre à la garantie ;
 *  - alert_rules.levels : paliers de la séquence (JSON), réglés par l'administrateur pour la catégorie GARANTIE.
 * Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('performance_guarantees', 'alerts_paused_at')) {
            Schema::table('performance_guarantees', function (Blueprint $t) {
                $t->dateTime('alerts_paused_at')->nullable();
                $t->unsignedBigInteger('alerts_paused_by')->nullable();
                $t->string('alerts_pause_reason', 500)->nullable();
                $t->unsignedTinyInteger('alerts_per_week')->nullable();
            });
        }
        if (!Schema::hasColumn('alert_rules', 'levels')) {
            Schema::table('alert_rules', fn (Blueprint $t) => $t->text('levels')->nullable());
        }
    }

    public function down(): void
    {
        // Pas de retour arrière automatique : la sauvegarde prise avant la migration fait foi.
    }
};
