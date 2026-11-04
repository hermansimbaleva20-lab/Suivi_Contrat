<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qualité d'autorisation des demandes logistiques, une par poste : C.A dans une antenne, HRAF ou COMPTABLE à la
 * coordination nationale. can_authorize_fleet en découle. Les comptes déjà habilités reçoivent la qualité de leur unité
 * (C.A pour une antenne ; HRAF si leur poste le mentionne, sinon COMPTABLE, à la coordination).
 * Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'logistics_authority')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('logistics_authority', 12)->nullable());
        }
        DB::statement("UPDATE users u LEFT JOIN organizational_units o ON o.id = u.organizational_unit_id LEFT JOIN positions p ON p.id = u.position_id
            SET u.logistics_authority = CASE WHEN o.type = 'POOL' THEN 'CA'
                WHEN UPPER(COALESCE(p.name, u.project_position, '')) LIKE '%HRAF%' THEN 'HRAF' ELSE 'COMPTABLE' END
            WHERE u.can_authorize_fleet = 1 AND u.logistics_authority IS NULL AND u.is_super_admin = 0 AND o.id IS NOT NULL");
    }

    public function down(): void
    {
        // Pas de retour arrière automatique : la sauvegarde prise avant la migration fait foi.
    }
};
