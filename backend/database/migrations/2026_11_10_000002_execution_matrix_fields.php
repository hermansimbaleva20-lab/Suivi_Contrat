<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matrice d'exécution du projet (décision du 10/11/2026) : colonnes « Composantes », « Budget en millions d'UC » et
 * « Performance environnementale et sociale ».
 *  - contracts.component : composante du projet à laquelle le contrat appartient (plusieurs contrats peuvent la partager) ;
 *  - contracts.component_budget : budget de la composante, tel qu'il figure dans la matrice (UC) ;
 *  - progress_entries.es_performance : performance environnementale et sociale constatée à la situation (Bon, Moyen, Mauvais).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            if (!Schema::hasColumn('contracts', 'component')) $t->string('component', 255)->nullable();
            if (!Schema::hasColumn('contracts', 'component_budget')) $t->decimal('component_budget', 18, 2)->nullable();
        });
        Schema::table('progress_entries', function (Blueprint $t) {
            if (!Schema::hasColumn('progress_entries', 'es_performance')) $t->string('es_performance', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('progress_entries', fn (Blueprint $t) => $t->dropColumn('es_performance'));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn(['component', 'component_budget']));
    }
};
