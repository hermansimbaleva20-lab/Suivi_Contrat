<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date de la caution d'une demande d'avance (décision du 23/11/2026) : à l'enregistrement de l'avance de démarrage d'un marché
 * de biens BAD ou national, la caution (garantie de restitution) porte sa propre date, distincte des dates du contrat, comme sa
 * date d'expiration ; le tableau « Caution d'avance de démarrage » les reprend. Les demandes déjà enregistrées gardent la date
 * de la demande à la place. Même contenu que database/sql/V41_date_caution_demande_avance.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contract_advances') && !Schema::hasColumn('contract_advances', 'guarantee_issue_date')) {
            Schema::table('contract_advances', function (Blueprint $t) {
                $t->date('guarantee_issue_date')->nullable()->after('guarantee_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contract_advances', 'guarantee_issue_date')) {
            Schema::table('contract_advances', fn (Blueprint $t) => $t->dropColumn('guarantee_issue_date'));
        }
    }
};
