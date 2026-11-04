<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chaque unité gère de façon autonome son stock et ses contrats de fournitures de bureau (décision du 04/11/2026) :
 * plus de catalogue commun, les N° de fiche repartent de 1 par unité, et un contrat de fournitures appartient à une unité.
 * Ce qui existait au catalogue commun, et les contrats de fournitures déjà saisis, reviennent à la coordination nationale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            if (!Schema::hasColumn('contracts', 'organizational_unit_id')) $t->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
        });
        $coordination = fn ($projectId) => DB::table('organizational_units')->where('project_id', $projectId)->where('type', 'COORDINATION')->orderBy('id')->value('id');
        foreach (DB::table('contracts')->where('goods_procedure', 'FOURNITURES_BUREAU')->whereNull('organizational_unit_id')->get(['id', 'project_id']) as $c) {
            DB::table('contracts')->where('id', $c->id)->update(['organizational_unit_id' => $coordination($c->project_id)]);
        }
        foreach (DB::table('stock_articles')->whereNull('organizational_unit_id')->distinct()->pluck('project_id') as $projectId) {
            DB::table('stock_articles')->where('project_id', $projectId)->whereNull('organizational_unit_id')->update(['organizational_unit_id' => $coordination($projectId)]);
        }

        $indexes = collect(DB::select('SHOW INDEX FROM stock_articles'))->pluck('Key_name')->unique();
        Schema::table('stock_articles', function (Blueprint $t) use ($indexes) {
            if (!$indexes->contains('stock_articles_unit_number_unique')) $t->unique(['project_id', 'organizational_unit_id', 'number'], 'stock_articles_unit_number_unique');
            if ($indexes->contains('stock_articles_project_number_unique')) $t->dropUnique('stock_articles_project_number_unique');
        });
    }

    public function down(): void
    {
        // Sans objet : les numéros de fiche peuvent désormais se répéter d'une unité à l'autre.
    }
};
