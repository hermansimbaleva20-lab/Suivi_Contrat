<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TDR §7 et §8, phase 3 — charroi automobile et groupes électrogènes, sur le modèle du « carnet de bord véhicule » du projet :
 *  - fleet_assets : parc (véhicules et groupes), avec les repères de réservoir, de consommation et d'entretien propres à chacun ;
 *  - fleet_logs : lignes du carnet de bord (courses des véhicules, relevés horaires des groupes), jamais modifiées : annulées avec motif ;
 *  - fleet_maintenances, fleet_insurances : entretiens et assurances.
 * Le compteur (« meter ») est en km pour un véhicule et en heures pour un groupe. Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fleet_assets')) {
            Schema::create('fleet_assets', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
                $t->string('kind', 10); // VEHICULE | GROUPE
                $t->string('name', 150); // ex. Toyota Fortuner, Groupe 20 kVA
                $t->string('registration', 40)->nullable(); // immatriculation ou n° de série
                $t->decimal('tank_capacity', 8, 2)->default(120); // L
                $t->decimal('consumption_rate', 8, 3)->nullable(); // L/km ou L/h
                $t->decimal('fuel_price', 8, 3)->default(1.37); // USD/L, prix proposé par défaut
                $t->decimal('fuel_alert_level', 8, 2)->nullable(); // L ; alerte réservoir (¼ = 30 L)
                $t->unsignedInteger('maintenance_interval'); // km ou h entre deux entretiens
                $t->unsignedInteger('maintenance_warning'); // km ou h depuis le dernier entretien avant avertissement
                $t->decimal('initial_meter', 12, 1)->default(0); // compteur à l'entrée dans le parc
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['project_id', 'name'], 'fleet_assets_project_name_unique');
            });
        }

        if (!Schema::hasTable('fleet_logs')) {
            Schema::create('fleet_logs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
                $t->foreignId('fleet_asset_id')->constrained('fleet_assets')->restrictOnDelete();
                $t->date('log_date');
                $t->string('requester', 150)->nullable();
                $t->string('destination', 190)->nullable();
                $t->string('purpose', 255)->nullable();
                $t->string('departure_time', 5)->nullable();
                $t->string('arrival_time', 5)->nullable();
                $t->decimal('meter_start', 12, 1);
                $t->decimal('meter_end', 12, 1);
                $t->decimal('fuel_level_before', 8, 2)->nullable(); // L dans le réservoir au départ
                $t->decimal('fuel_purchased', 8, 2)->nullable(); // L achetés (pas à chaque course)
                $t->decimal('fuel_price', 8, 3)->nullable(); // USD/L
                $t->decimal('lubricant', 8, 2)->nullable(); // L
                $t->string('observation', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('authorized_by')->nullable();
                $t->timestamp('authorized_at')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->unsignedBigInteger('cancelled_by')->nullable();
                $t->string('cancel_reason', 500)->nullable();
                $t->timestamps();
                $t->index(['project_id', 'fleet_asset_id', 'log_date'], 'fleet_logs_asset_date_idx');
                $t->index(['project_id', 'organizational_unit_id', 'log_date'], 'fleet_logs_unit_date_idx');
            });
        }

        if (!Schema::hasTable('fleet_maintenances')) {
            Schema::create('fleet_maintenances', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
                $t->foreignId('fleet_asset_id')->constrained('fleet_assets')->restrictOnDelete();
                $t->date('maintenance_date');
                $t->decimal('meter_reading', 12, 1);
                $t->string('designation', 255);
                $t->decimal('cost', 14, 2)->nullable();
                $t->string('provider', 190)->nullable();
                $t->string('observation', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['project_id', 'fleet_asset_id', 'maintenance_date'], 'fleet_maint_asset_date_idx');
            });
        }

        if (!Schema::hasTable('fleet_insurances')) {
            Schema::create('fleet_insurances', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
                $t->foreignId('fleet_asset_id')->constrained('fleet_assets')->restrictOnDelete();
                $t->date('start_date');
                $t->date('end_date');
                $t->string('designation', 255); // assureur, n° de police
                $t->decimal('cost', 14, 2)->nullable();
                $t->string('observation', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['project_id', 'fleet_asset_id', 'end_date'], 'fleet_ins_asset_end_idx');
            });
        }

        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'can_view_fleet')) $t->boolean('can_view_fleet')->default(false);
            if (!Schema::hasColumn('users', 'can_manage_fleet')) $t->boolean('can_manage_fleet')->default(false);
            if (!Schema::hasColumn('users', 'can_authorize_fleet')) $t->boolean('can_authorize_fleet')->default(false);
        });
    }

    public function down(): void
    {
        // Volontairement non destructif : les carnets de bord sont des pièces justificatives (TDR §3).
    }
};
