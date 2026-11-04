<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modèles des messages adressés aux entreprises (App\Support\AlertTemplates) et historique des envois par contrat :
 *  - alert_templates : objet et texte modifiés d'un modèle (vigilance, retard, satisfaction ; garantie ou contrat) ;
 *    sans ligne, le modèle par défaut s'applique ;
 *  - alert_dispatches : contrat, garantie, n° de garantie, nature du message et texte envoyé, pour le tableau
 *    « Historique des envois » filtré par contrat ou par n° de garantie. Les relances de garantie déjà envoyées sont
 *    rattachées d'après leur clé (GARANTIE:id:…) ; les récapitulatifs internes restent hors de ce tableau.
 * Migration additive et relançable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('alert_templates')) {
            Schema::create('alert_templates', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->string('code', 40); // GARANTIE_VIGILANCE, CONTRAT_RETARD…
                $t->string('subject', 255);
                $t->text('body');
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->unique(['project_id', 'code'], 'alert_templates_code_unique');
            });
        }
        if (!Schema::hasColumn('alert_dispatches', 'contract_id')) {
            Schema::table('alert_dispatches', function (Blueprint $t) {
                $t->unsignedBigInteger('contract_id')->nullable();
                $t->unsignedBigInteger('performance_guarantee_id')->nullable();
                $t->string('guarantee_number', 190)->nullable();
                $t->string('message_type', 20)->nullable(); // VIGILANCE | RETARD | SATISFACTION
                $t->text('body')->nullable();
                $t->index(['project_id', 'contract_id'], 'alert_dispatches_contract_idx');
            });
        }
        DB::statement("UPDATE alert_dispatches d JOIN performance_guarantees g ON d.alert_key LIKE CONCAT('GARANTIE:', g.id, ':%')
            SET d.performance_guarantee_id = g.id, d.contract_id = g.contract_id, d.guarantee_number = g.account_number
            WHERE d.performance_guarantee_id IS NULL AND d.category = 'GARANTIE'");
    }

    public function down(): void
    {
        // Pas de retour arrière automatique : la sauvegarde prise avant la migration fait foi.
    }
};
