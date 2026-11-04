<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fournitures de bureau : suivi par le stock uniquement (décision du 04/11/2026), donc pas d'avance de démarrage.
 * Relançable : remet à zéro le taux des contrats concernés, sauf si une avance a déjà été enregistrée.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('contracts')->where('goods_procedure', 'FOURNITURES_BUREAU')->where('initial_financing_rate', '>', 0)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('contract_advances')->whereColumn('contract_advances.contract_id', 'contracts.id')->where('contract_advances.status', '!=', 'REJETEE'))
            ->update(['initial_financing_rate' => 0]);
    }

    public function down(): void
    {
        // Sans objet : l'ancien taux n'est pas conservé.
    }
};
