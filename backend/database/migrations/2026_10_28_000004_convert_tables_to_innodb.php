<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les bases créées sur WAMP sont en MyISAM (moteur par défaut du serveur) : les transactions du code (DB::transaction)
 * et les clés étrangères y sont ignorées, et une table InnoDB ne peut pas référencer une table MyISAM.
 * Conversion en InnoDB (TDR §4.1) : les données sont conservées, seules les tables encore en MyISAM sont touchées.
 * Relançable. Faire une sauvegarde avant (php artisan migrate le rappelle dans le README).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;
        $tables = DB::select("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE = 'MyISAM'");
        foreach ($tables as $row) {
            DB::statement('ALTER TABLE `' . str_replace('`', '', $row->t) . '` ENGINE = InnoDB');
        }
    }

    public function down(): void
    {
        // Pas de retour à MyISAM : il ferait perdre les garanties transactionnelles.
    }
};
