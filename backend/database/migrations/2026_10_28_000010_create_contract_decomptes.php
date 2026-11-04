<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des décomptes (TDR §9.3–9.4), sur le modèle « Situation du contrat » du projet : chaque décompte apporté par
 * l'entreprise est saisi, puis validé ou rejeté par le comptable. Les retenues (remboursement de l'avance de démarrage,
 * retenue de garantie de bonne exécution) sont calculées par App\Support\DecompteLedger à partir des taux figés ici.
 * Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contract_decomptes')) {
            Schema::create('contract_decomptes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
                $t->unsignedInteger('line_no'); // N° de ligne de la situation
                $t->string('number', 60); // ex. DECOMPTE N°1
                $t->date('operation_date');
                $t->decimal('gross_amount', 15, 2); // montant du décompte, USD
                $t->decimal('advance_rate', 6, 4)->default(0); // 0,20 si l'avance de démarrage a été perçue ; 0 pour un contrat de service
                $t->decimal('guarantee_rate', 6, 4)->default(0); // taux de la garantie de bonne exécution (0,05)
                $t->string('status', 12)->default('EN_ATTENTE'); // EN_ATTENTE | VALIDE | REJETE
                $t->string('observation', 500)->nullable();
                $t->unsignedBigInteger('submitted_by')->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->string('decision_comment', 500)->nullable();
                $t->timestamps();
                $t->unique(['contract_id', 'line_no'], 'decomptes_contract_line_unique');
                $t->index(['project_id', 'status'], 'decomptes_project_status_idx');
            });
        }
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'can_validate_decomptes')) $t->boolean('can_validate_decomptes')->default(false);
        });
    }

    public function down(): void
    {
        // Volontairement non destructif : les décomptes sont des pièces comptables (TDR §3).
    }
};
