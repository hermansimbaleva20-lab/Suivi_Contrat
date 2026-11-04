<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Décomptes : date de l'opération de décision, saisie par le comptable (obligatoire pour un rejet, avec le motif).
 * Les décisions déjà prises reçoivent la date de leur enregistrement. Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contract_decomptes', 'decision_date')) {
            Schema::table('contract_decomptes', fn (Blueprint $t) => $t->date('decision_date')->nullable()->after('decided_at'));
        }
        DB::table('contract_decomptes')->whereNull('decision_date')->whereNotNull('decided_at')->update(['decision_date' => DB::raw('DATE(decided_at)')]);
    }

    public function down(): void
    {
        // Pas de retour arrière automatique : la sauvegarde prise avant la migration fait foi.
    }
};
