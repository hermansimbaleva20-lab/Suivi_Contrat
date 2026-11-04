<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Comptes : la « Fonction » disparaît, le poste occupé dans le projet tient lieu de rôle affiché. Le super-admin désigne
 *   les comptes qui consultent les pools au niveau national (can_view_pools, lecture seule).
 * - Notifications adressées à un compte (décompte à valider, course à autoriser, décision rendue).
 * - Carnet de bord : la course est adressée à un valideur choisi (HRAF ou comptable) qui l'autorise ou fait objection ;
 *   le lubrifiant devient un texte, « PLEIN » par défaut.
 * Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'function')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('function'));
        }
        if (!Schema::hasColumn('users', 'can_view_pools')) {
            Schema::table('users', fn (Blueprint $t) => $t->boolean('can_view_pools')->default(false));
        }

        if (!Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('kind', 40);
                $t->string('title', 190);
                $t->string('body', 500)->nullable();
                $t->string('link', 190)->nullable();
                $t->string('entity_name', 60)->nullable();
                $t->string('entity_id', 40)->nullable();
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'read_at'], 'user_notifications_user_read_idx');
                $t->index(['entity_name', 'entity_id'], 'user_notifications_entity_idx');
            });
        }

        if (!Schema::hasColumn('fleet_logs', 'assigned_to')) {
            Schema::table('fleet_logs', function (Blueprint $t) {
                $t->unsignedBigInteger('assigned_to')->nullable()->after('created_by');
                $t->unsignedBigInteger('objected_by')->nullable()->after('authorized_at');
                $t->timestamp('objected_at')->nullable()->after('objected_by');
                $t->string('objection_reason', 500)->nullable()->after('objected_at');
                $t->index(['assigned_to', 'authorized_at'], 'fleet_logs_assignee_idx');
            });
        }
        $lubricant = DB::selectOne("SELECT DATA_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fleet_logs' AND COLUMN_NAME = 'lubricant'");
        if ($lubricant && strtolower($lubricant->t) !== 'varchar') {
            DB::statement("ALTER TABLE fleet_logs MODIFY lubricant VARCHAR(50) NULL DEFAULT 'PLEIN'");
            // 2.00 → « 2 L » : les quantités saisies avant le changement restent lisibles.
            DB::statement("UPDATE fleet_logs SET lubricant = CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM lubricant)), ' L') WHERE lubricant REGEXP '^[0-9]+(\\\\.[0-9]+)?$'");
        }
    }

    public function down(): void
    {
        // Pas de retour arrière automatique : la sauvegarde prise avant la migration fait foi.
    }
};
