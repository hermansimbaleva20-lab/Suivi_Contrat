<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TDR §6, phase 3 — gestion des stocks, sur le modèle des documents papier du projet :
 *  - catalogue des articles et « prix unitaires évalués » (ARTICLE, UNITÉ, PU $), commun au projet ;
 *  - « fiche de stock » par article et par unité (DATE, ORIGINE OU DESTINATION, ENTRÉE, SORTIE, STOCK, OBSERVATION).
 * Un mouvement n'est jamais modifié ni supprimé : il est annulé avec un motif, pour que la fiche reste traçable.
 * Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('stock_articles')) {
            Schema::create('stock_articles', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->unsignedInteger('number'); // N° de la fiche de stock (001, 002…)
                $t->string('designation', 255);
                $t->string('unit', 40); // Pce, Pqt, Pqt/100pces, Rame…
                $t->decimal('unit_price', 18, 2)->default(0); // PU évalué, en USD (décision D9)
                $t->decimal('alert_threshold', 14, 2)->nullable(); // stock minimal avant alerte
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['project_id', 'number'], 'stock_articles_project_number_unique');
                $t->unique(['project_id', 'designation'], 'stock_articles_project_designation_unique');
            });
        }

        if (!Schema::hasTable('stock_movements')) {
            Schema::create('stock_movements', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
                $t->foreignId('stock_article_id')->constrained('stock_articles')->restrictOnDelete();
                $t->date('movement_date');
                $t->string('type', 10); // ENTREE | SORTIE
                $t->decimal('quantity', 14, 2);
                $t->string('origin_destination', 190);
                $t->string('observation', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->unsignedBigInteger('cancelled_by')->nullable();
                $t->string('cancel_reason', 500)->nullable();
                $t->timestamps();
                $t->index(['project_id', 'organizational_unit_id', 'stock_article_id', 'movement_date'], 'stock_movements_card_idx');
            });
        }

        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'can_view_stock')) $t->boolean('can_view_stock')->default(false);
            if (!Schema::hasColumn('users', 'can_manage_stock')) $t->boolean('can_manage_stock')->default(false);
        });
    }

    public function down(): void
    {
        // Volontairement non destructif : les fiches de stock sont des pièces justificatives (TDR §3).
    }
};
