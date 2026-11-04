<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Décisions du 28/10/2026 :
 *  - chaque poste est rattaché à une unité (à l'inscription, le choix de l'unité filtre les postes) ; un poste sans unité reste
 *    proposé à toutes les unités (postes créés avant cette migration) ;
 *  - stock : colisage paramétrable par article (ex. 1 carton = 12 pièces) ; une entrée enregistre la livraison de l'entreprise
 *    titulaire du contrat (fournisseur, n° de bon de livraison) ; chaque ligne de fiche porte un N° d'opération stable ;
 *  - le super-administrateur appartient à la coordination nationale.
 * Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('positions', 'organizational_unit_id')) {
            Schema::table('positions', fn (Blueprint $t) => $t->foreignId('organizational_unit_id')->nullable()->after('project_id')->constrained('organizational_units')->nullOnDelete());
        }
        // Un même intitulé (ex. « Logisticien ») peut exister dans chaque unité. Le nouvel index commence par project_id :
        // il remplace l'ancien pour la clé étrangère avant que celui-ci soit supprimé.
        if (!Schema::hasIndex('positions', 'positions_project_unit_name_unique')) {
            Schema::table('positions', fn (Blueprint $t) => $t->unique(['project_id', 'organizational_unit_id', 'name'], 'positions_project_unit_name_unique'));
        }
        if (Schema::hasIndex('positions', 'positions_project_name_unique')) {
            Schema::table('positions', fn (Blueprint $t) => $t->dropUnique('positions_project_name_unique'));
        }

        Schema::table('stock_articles', function (Blueprint $t) {
            if (!Schema::hasColumn('stock_articles', 'pack_size')) $t->decimal('pack_size', 10, 2)->nullable()->after('unit'); // contenu d'une unité
            if (!Schema::hasColumn('stock_articles', 'pack_unit')) $t->string('pack_unit', 40)->nullable()->after('pack_size'); // ex. pièces
        });

        Schema::table('stock_movements', function (Blueprint $t) {
            if (!Schema::hasColumn('stock_movements', 'operation_no')) $t->unsignedInteger('operation_no')->nullable()->after('stock_article_id');
            if (!Schema::hasColumn('stock_movements', 'company_id')) $t->foreignId('company_id')->nullable()->after('origin_destination')->constrained('companies')->nullOnDelete();
            if (!Schema::hasColumn('stock_movements', 'reference')) $t->string('reference', 100)->nullable()->after('company_id'); // n° de bon de livraison
        });

        // N° d'opération des lignes existantes : ordre chronologique de chaque fiche (article × unité).
        $groups = DB::table('stock_movements')->whereNull('operation_no')->select('organizational_unit_id', 'stock_article_id')->distinct()->get();
        foreach ($groups as $g) {
            $next = (int) DB::table('stock_movements')->where('organizational_unit_id', $g->organizational_unit_id)->where('stock_article_id', $g->stock_article_id)->max('operation_no');
            $ids = DB::table('stock_movements')->where('organizational_unit_id', $g->organizational_unit_id)->where('stock_article_id', $g->stock_article_id)
                ->whereNull('operation_no')->orderBy('movement_date')->orderBy('id')->pluck('id');
            foreach ($ids as $id) DB::table('stock_movements')->where('id', $id)->update(['operation_no' => ++$next]);
        }

        // Super-administrateur : rattaché à la coordination nationale de son projet s'il n'a pas d'unité.
        foreach (DB::table('users')->where('is_super_admin', true)->whereNull('organizational_unit_id')->get(['id', 'project_id']) as $u) {
            $cn = DB::table('organizational_units')->where('project_id', $u->project_id)->where('type', 'COORDINATION')->orderBy('id')->value('id');
            if ($cn) DB::table('users')->where('id', $u->id)->update(['organizational_unit_id' => $cn]);
        }
    }

    public function down(): void
    {
        // Volontairement non destructif (TDR §3).
    }
};
