<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultation des modèles et de l'historique des alertes (décision du 05/11/2026) : le super-administrateur,
 * et les comptes à qui il accorde cette habilitation. Être administrateur ne suffit pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'can_view_alert_history')) $t->boolean('can_view_alert_history')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('can_view_alert_history'));
    }
};
