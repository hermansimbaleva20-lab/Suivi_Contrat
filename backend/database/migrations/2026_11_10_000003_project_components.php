<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Composantes du projet (décision du 10/11/2026) : enregistrées au préalable, sur une page réservée (Administration ›
 * Composantes), avec leur budget en UC ; un contrat en choisit une, facultativement (contracts.component_id).
 * Les colonnes texte contracts.component / component_budget (V37) ne sont plus lues : leurs valeurs sont reprises ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_components')) {
            Schema::create('project_components', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->string('name', 255);
                $t->decimal('budget', 18, 2)->nullable(); // budget de la composante, en UC (colonne de la matrice d'exécution)
                $t->text('description')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['project_id', 'name'], 'project_components_project_name_unique');
            });
        }
        if (!Schema::hasColumn('contracts', 'component_id')) {
            Schema::table('contracts', fn (Blueprint $t) => $t->foreignId('component_id')->nullable()->constrained('project_components')->nullOnDelete());
        }
        // Reprise des composantes saisies en texte libre sur les contrats.
        if (Schema::hasColumn('contracts', 'component')) {
            foreach (DB::table('contracts')->whereNotNull('component')->where('component', '!=', '')->whereNull('component_id')->get(['id', 'project_id', 'component', 'component_budget']) as $c) {
                $name = trim((string) $c->component);
                $id = DB::table('project_components')->where('project_id', $c->project_id)->where('name', $name)->value('id');
                if (!$id) {
                    $id = DB::table('project_components')->insertGetId(['project_id' => $c->project_id, 'name' => $name, 'budget' => $c->component_budget, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                } elseif ($c->component_budget !== null) {
                    DB::table('project_components')->where('id', $id)->whereNull('budget')->update(['budget' => $c->component_budget]);
                }
                DB::table('contracts')->where('id', $c->id)->update(['component_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->dropForeign(['component_id']);
            $t->dropColumn('component_id');
        });
        Schema::dropIfExists('project_components');
    }
};
