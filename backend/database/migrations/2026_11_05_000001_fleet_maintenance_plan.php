<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programmation du prochain entretien (décision du 05/11/2026) : compteur du dernier entretien et compteur du prochain,
 * sur l'engin (programmation directe) et sur chaque entretien enregistré. Alerte aux trois quarts du parcours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_assets', function (Blueprint $t) {
            if (!Schema::hasColumn('fleet_assets', 'maintenance_last_meter')) $t->decimal('maintenance_last_meter', 12, 1)->nullable();
            if (!Schema::hasColumn('fleet_assets', 'maintenance_next_meter')) $t->decimal('maintenance_next_meter', 12, 1)->nullable();
        });
        Schema::table('fleet_maintenances', function (Blueprint $t) {
            if (!Schema::hasColumn('fleet_maintenances', 'next_meter')) $t->decimal('next_meter', 12, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_maintenances', fn (Blueprint $t) => $t->dropColumn('next_meter'));
        Schema::table('fleet_assets', fn (Blueprint $t) => $t->dropColumn(['maintenance_last_meter', 'maintenance_next_meter']));
    }
};
