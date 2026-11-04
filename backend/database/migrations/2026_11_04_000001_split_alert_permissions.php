<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * « Voir alertes » est scindée en trois habilitations, au choix du super-admin : alertes des contrats, de bonne exécution
 * et logistiques (stock, charroi, groupes électrogènes). Reprise des droits, une seule fois : qui voyait les alertes garde
 * les contrats et les garanties ; les alertes logistiques vont aux comptes qui gèrent le stock ou le charroi, ou qui
 * autorisent le carnet de bord. La colonne can_view_alerts reste en place mais ne sert plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'can_view_alerts_contracts')) return;
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('can_view_alerts_contracts')->default(false);
            $t->boolean('can_view_alerts_guarantees')->default(false);
            $t->boolean('can_view_alerts_logistics')->default(false);
        });
        DB::statement('UPDATE users SET can_view_alerts_contracts = can_view_alerts, can_view_alerts_guarantees = can_view_alerts');
        DB::statement('UPDATE users SET can_view_alerts_logistics = 1 WHERE can_manage_stock = 1 OR can_manage_fleet = 1 OR can_authorize_fleet = 1');
    }

    public function down(): void
    {
        // Pas de retour arrière automatique : la sauvegarde prise avant la migration fait foi.
    }
};
