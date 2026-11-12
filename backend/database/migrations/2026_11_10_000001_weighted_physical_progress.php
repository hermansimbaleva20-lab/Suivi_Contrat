<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avancement physique pondéré par les montants (décision du 10/11/2026) :
 *  - contracts.installation_cost : coût de l'installation de chantier et des travaux préparatoires ;
 *  - contract_sites.amount : montant prévu de chaque site ;
 *  - progress_entries.is_installation : situation qui porte sur l'installation de chantier (sans site).
 * Montant du contrat = installation + somme des sites ; avancement = somme des taux pondérés par ces montants.
 * Les contrats dont les sites n'ont pas de montant gardent la moyenne simple des sites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            if (!Schema::hasColumn('contracts', 'installation_cost')) $t->decimal('installation_cost', 15, 2)->nullable();
        });
        Schema::table('contract_sites', function (Blueprint $t) {
            if (!Schema::hasColumn('contract_sites', 'amount')) $t->decimal('amount', 15, 2)->nullable();
        });
        Schema::table('progress_entries', function (Blueprint $t) {
            if (!Schema::hasColumn('progress_entries', 'is_installation')) $t->boolean('is_installation')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('progress_entries', fn (Blueprint $t) => $t->dropColumn('is_installation'));
        Schema::table('contract_sites', fn (Blueprint $t) => $t->dropColumn('amount'));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('installation_cost'));
    }
};
