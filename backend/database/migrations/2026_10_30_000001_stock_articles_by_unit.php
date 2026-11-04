<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Articles de stock par unité : chaque antenne ajoute ses propres articles (organizational_unit_id), la coordination
 * nationale tient le catalogue commun (organizational_unit_id NULL). Un article peut venir d'un contrat de fournitures
 * (contract_id, import du fichier des articles du contrat) ou être ajouté un par un, sans contrat.
 * La désignation n'est plus unique dans tout le projet mais par unité (catalogue commun compris), contrôle fait par l'API.
 * Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('stock_articles', 'organizational_unit_id')) {
            Schema::table('stock_articles', function (Blueprint $t) {
                $t->foreignId('organizational_unit_id')->nullable()->after('project_id')->constrained('organizational_units')->restrictOnDelete();
                $t->foreignId('contract_id')->nullable()->after('organizational_unit_id')->constrained('contracts')->nullOnDelete();
            });
        }
        $hasUnique = DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_articles' AND INDEX_NAME = 'stock_articles_project_designation_unique'");
        if ($hasUnique && (int) $hasUnique->n > 0) {
            Schema::table('stock_articles', function (Blueprint $t) {
                $t->index(['project_id', 'designation'], 'stock_articles_project_designation_idx');
                $t->dropUnique('stock_articles_project_designation_unique');
            });
        }
    }

    public function down(): void
    {
        // Pas de retour arrière automatique : la sauvegarde prise avant la migration fait foi.
    }
};
