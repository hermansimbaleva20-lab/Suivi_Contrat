<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Composantes détaillées (décision du 16/11/2026), comme la matrice d'état d'exécution du projet :
 *   composante (« A. Développement des infrastructures ») → catégories à intitulé libre (« Travaux »), chacune avec son coût
 *   en UC → activités de la catégorie (« Réalisation de 22 systèmes d'AEP… »), chacune avec son budget en UC.
 * Le montant total de la composante est la somme des coûts de ses catégories (calculé, plus saisi). Un contrat se rattache à
 * une activité (contracts.activity_id) ; contracts.component_id en est déduit et reste lu par les écrans existants.
 * Habilitation « Modifier ou supprimer les articles du stock » (users.can_edit_stock_articles), octroyée une à une par le
 * super-admin, y compris aux administrateurs, et séparée de la gestion courante du stock : reprise pour les comptes qui
 * l'exerçaient déjà (gestionnaires de stock et administrateurs).
 * Même contenu que base_de_donnees/mise_a_jour_2026-11-16.sql (hébergement sans artisan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('component_categories')) {
            Schema::create('component_categories', function (Blueprint $t) {
                $t->id();
                $t->foreignId('component_id')->constrained('project_components')->cascadeOnDelete();
                $t->string('name', 150);
                $t->decimal('cost', 18, 2)->nullable(); // coût de la catégorie, en UC
                $t->timestamps();
                $t->unique(['component_id', 'name'], 'component_categories_component_name_unique');
            });
        }
        if (!Schema::hasTable('component_activities')) {
            Schema::create('component_activities', function (Blueprint $t) {
                $t->id();
                $t->foreignId('category_id')->constrained('component_categories')->cascadeOnDelete();
                $t->string('name', 500);
                $t->decimal('budget', 18, 2)->nullable(); // budget de l'activité, en UC
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->index('category_id', 'component_activities_category_idx');
            });
        }
        if (!Schema::hasColumn('contracts', 'activity_id')) {
            Schema::table('contracts', fn (Blueprint $t) => $t->foreignId('activity_id')->nullable()->after('component_id')->constrained('component_activities')->nullOnDelete());
        }
        if (!Schema::hasColumn('users', 'can_edit_stock_articles')) {
            Schema::table('users', fn (Blueprint $t) => $t->boolean('can_edit_stock_articles')->default(false));
            // Personne ne perd ce droit à la mise à jour : gestionnaires de stock et administrateurs l'exerçaient déjà.
            DB::table('users')->where(fn ($w) => $w->where('can_manage_stock', true)->orWhereRaw("UPPER(role) IN ('ADMIN', 'ADMINISTRATEUR')"))->update(['can_edit_stock_articles' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'can_edit_stock_articles')) Schema::table('users', fn (Blueprint $t) => $t->dropColumn('can_edit_stock_articles'));
        if (Schema::hasColumn('contracts', 'activity_id')) {
            Schema::table('contracts', function (Blueprint $t) {
                $t->dropForeign(['activity_id']);
                $t->dropColumn('activity_id');
            });
        }
        Schema::dropIfExists('component_activities');
        Schema::dropIfExists('component_categories');
    }
};
