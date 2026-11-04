<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - alert_recipients.position_id : poste occupé dans le projet par chaque destinataire (personnel du projet).
 * - Validation des décomptes (décision du 03/11/2026) : elle passe du comptable au chef de cellule infrastructures.
 *   L'habilitation can_validate_decomptes est retirée aux comptes au poste COMPTABLE et accordée à ceux au poste de chef
 *   de cellule infrastructures. Le super-admin peut ensuite l'ajuster dans Utilisateurs : ce transfert ne se rejoue pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('alert_recipients', 'position_id')) {
            Schema::table('alert_recipients', fn (Blueprint $t) => $t->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete());
        }
        DB::statement("UPDATE users u LEFT JOIN positions p ON p.id = u.position_id SET u.can_validate_decomptes = 0
            WHERE u.can_validate_decomptes = 1 AND UPPER(TRIM(COALESCE(p.name, u.project_position, ''))) = 'COMPTABLE'");
        DB::statement("UPDATE users u JOIN positions p ON p.id = u.position_id SET u.can_validate_decomptes = 1
            WHERE UPPER(p.name) LIKE 'CHEF DE CELLULE INFRASTRUCTURE%'");
    }

    public function down(): void
    {
        // Pas de retour arrière automatique : la sauvegarde prise avant la migration fait foi.
    }
};
