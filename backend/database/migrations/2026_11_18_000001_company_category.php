<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catégorie de chaque entreprise du projet (décision du 18/11/2026) : TRAVAUX, BIENS ou SERVICE, comme la catégorie des
 * contrats. Choisie à l'enregistrement de l'entreprise ; le formulaire du contrat demande la catégorie d'abord et ne propose
 * ensuite que les entreprises de cette catégorie.
 * Reprise : une entreprise dont tous les contrats sont d'une même catégorie la reçoit ; les autres restent « non renseignée »
 * jusqu'à ce qu'on la choisisse. Même contenu que base_de_donnees/mise_a_jour_2026-11-18.sql (hébergement sans artisan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'category')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->string('category', 20)->nullable()->after('name');
                $t->index(['project_id', 'category'], 'companies_project_category_idx');
            });
        }
        DB::statement("UPDATE companies c SET c.category = (SELECT MIN(k.category) FROM contracts k WHERE k.company_id = c.id)
            WHERE c.category IS NULL
              AND (SELECT COUNT(DISTINCT k.category) FROM contracts k WHERE k.company_id = c.id AND k.category IN ('TRAVAUX', 'BIENS', 'SERVICE')) = 1
              AND NOT EXISTS (SELECT 1 FROM contracts k WHERE k.company_id = c.id AND (k.category IS NULL OR k.category NOT IN ('TRAVAUX', 'BIENS', 'SERVICE')))");
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'category')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->dropIndex('companies_project_category_idx');
                $t->dropColumn('category');
            });
        }
    }
};
