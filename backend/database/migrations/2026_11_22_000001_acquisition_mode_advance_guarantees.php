<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode d'acquisition du marché et caution d'avance de démarrage (décision du 22/11/2026), sur le modèle des tableaux de
 * suivi du projet (« Caution d'avance de démarrage », « Garantie de bonne exécution ») :
 * - contracts.acquisition_mode : AON, AOI, CF (consultation de fournisseurs), ED (entente directe), ou le texte d'un autre
 *   mode ; saisi une fois dans les informations du contrat, repris dans les deux tableaux ;
 * - advance_guarantees : la caution d'avance de démarrage d'un contrat (une par contrat), facultative et complétée plus
 *   tard : référence, banque, date de la caution, date d'expiration (indépendantes des dates du marché), observations.
 * Même contenu que database/sql/V40_mode_acquisition_caution_avance.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contracts', 'acquisition_mode')) {
            Schema::table('contracts', function (Blueprint $t) {
                $t->string('acquisition_mode', 80)->nullable()->after('category');
            });
        }
        if (!Schema::hasTable('advance_guarantees')) {
            Schema::create('advance_guarantees', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->foreignId('contract_id')->unique()->constrained('contracts')->cascadeOnDelete();
                $t->string('reference', 120);
                $t->string('bank_name', 191)->nullable();
                $t->date('issue_date')->nullable();
                $t->date('end_date')->nullable();
                $t->string('observation', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_guarantees');
        if (Schema::hasColumn('contracts', 'acquisition_mode')) {
            Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('acquisition_mode'));
        }
    }
};
