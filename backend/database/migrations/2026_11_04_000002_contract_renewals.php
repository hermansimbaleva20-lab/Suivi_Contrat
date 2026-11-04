<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renouvellement d'un contrat de BIENS (fournitures de bureau…) : le même contrat reçoit une nouvelle période, un montant
 * ajouté et un numéro à suffixe (144 → 144/R1).
 *  - contracts.renewal_no : nombre de renouvellements ;
 *  - contracts.renewed_amount : cumul des montants ajoutés, hors de l'assiette de l'avance de démarrage ;
 *  - contract_renewals : historique (numéros, période, montants, dernière ligne de décompte au moment du renouvellement).
 * Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contracts', 'renewal_no')) {
            Schema::table('contracts', function (Blueprint $t) {
                $t->unsignedInteger('renewal_no')->default(0);
                $t->decimal('renewed_amount', 15, 2)->default(0);
            });
        }
        if (!Schema::hasTable('contract_renewals')) {
            Schema::create('contract_renewals', function (Blueprint $t) {
                $t->id();
                $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
                $t->unsignedInteger('renewal_no');
                $t->date('date');
                $t->string('previous_reference');
                $t->string('new_reference');
                $t->decimal('added_amount', 15, 2)->default(0);
                $t->decimal('previous_amount', 15, 2);
                $t->date('previous_end_date')->nullable();
                $t->date('period_start');
                $t->date('new_end_date');
                $t->unsignedInteger('months');
                $t->unsignedInteger('last_line_no')->default(0); // dernière ligne de décompte avant le renouvellement
                $t->text('reason');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['contract_id', 'renewal_no'], 'contract_renewals_no_unique');
            });
        }
    }

    public function down(): void
    {
        // Pas de retour arrière automatique : la sauvegarde prise avant la migration fait foi.
    }
};
