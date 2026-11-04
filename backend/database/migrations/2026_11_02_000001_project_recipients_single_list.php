<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une seule liste pour le personnel du projet : alert_recipients. Les adresses des 4 champs « Destinataire » des paramètres
 * SMTP qui n'y figurent pas encore y sont reprises (toutes les catégories), puis ces champs sont vidés, pour qu'une adresse
 * supprimée de la liste ne reçoive plus rien et ne soit pas reprise par un script rejoué. Les colonnes restent en place.
 * Migration relançable.
 */
return new class extends Migration
{
    private const CATEGORIES = 'RETARD,GARANTIE,DECOMPTE,STOCK,CHARROI,GROUPE';

    public function up(): void
    {
        if (!Schema::hasColumn('smtp_settings', 'recipient1') || !Schema::hasTable('alert_recipients')) return;
        foreach (DB::table('smtp_settings')->get() as $s) {
            foreach ([$s->recipient1, $s->recipient2, $s->recipient3, $s->recipient4] as $raw) {
                $email = strtolower(trim((string) $raw));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || DB::table('alert_recipients')->where('project_id', $s->project_id)->where('email', $email)->exists()) continue;
                DB::table('alert_recipients')->insert(['project_id' => $s->project_id, 'email' => $email, 'categories' => self::CATEGORIES, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('smtp_settings')->update(['recipient1' => null, 'recipient2' => null, 'recipient3' => null, 'recipient4' => null]);
    }

    public function down(): void
    {
        // Pas de retour arrière automatique : la sauvegarde prise avant la migration fait foi.
    }
};
