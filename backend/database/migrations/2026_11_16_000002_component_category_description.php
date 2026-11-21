<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Description de chaque catégorie d'une composante (décision du 16/11/2026) : ce qui doit être réalisé dans la catégorie.
 * Obligatoire à la saisie (contrôlé par l'API) ; la colonne reste facultative pour les catégories déjà enregistrées.
 * Même contenu que base_de_donnees/mise_a_jour_2026-11-16_description.sql (hébergement sans artisan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('component_categories') && !Schema::hasColumn('component_categories', 'description')) {
            Schema::table('component_categories', fn (Blueprint $t) => $t->text('description')->nullable()->after('cost'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('component_categories', 'description')) Schema::table('component_categories', fn (Blueprint $t) => $t->dropColumn('description'));
    }
};
