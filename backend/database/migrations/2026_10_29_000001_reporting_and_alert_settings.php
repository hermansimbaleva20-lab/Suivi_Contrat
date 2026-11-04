<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TDR §10 et §11 :
 *  - report_snapshots : états figés d'une période (trimestre, mois, année ou période libre), conservés tels quels pour
 *    comparer l'évolution d'un trimestre à l'autre ;
 *  - alert_recipients : adresses qui reçoivent les alertes, par catégorie et par unité, gérées par le super-admin ;
 *  - alert_rules : séquence d'envoi de chaque catégorie (activation, relance tous les N jours, nombre maximal d'envois) ;
 *  - alert_dispatches : historique des envois (et des échecs), qui fait respecter la séquence.
 * Les 4 destinataires SMTP existants sont repris comme destinataires de toutes les catégories. Migration additive et relançable.
 */
return new class extends Migration
{
    public const CATEGORIES = ['RETARD', 'GARANTIE', 'DECOMPTE', 'STOCK', 'CHARROI', 'GROUPE'];

    public function up(): void
    {
        if (!Schema::hasTable('report_snapshots')) {
            Schema::create('report_snapshots', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->unsignedBigInteger('phase_id')->nullable();
                $t->string('period_type', 12); // TRIMESTRE | MOIS | ANNEE | LIBRE
                $t->string('label', 60); // ex. T1 2026
                $t->date('period_start');
                $t->date('period_end');
                $t->longText('data'); // indicateurs figés (JSON)
                $t->string('comment', 2000)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['project_id', 'phase_id', 'label'], 'report_snapshots_label_unique');
            });
        }
        if (!Schema::hasTable('alert_recipients')) {
            Schema::create('alert_recipients', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->string('email', 190);
                $t->string('name', 150)->nullable();
                $t->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete(); // null = toutes les unités
                $t->string('categories', 255); // liste séparée par des virgules
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['project_id', 'email'], 'alert_recipients_email_unique');
            });
        }
        if (!Schema::hasTable('alert_rules')) {
            Schema::create('alert_rules', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->string('category', 20);
                $t->boolean('is_enabled')->default(true);
                $t->unsignedSmallInteger('interval_days')->default(7); // relance tous les N jours tant que l'alerte persiste
                $t->unsignedSmallInteger('max_sends')->default(3); // nombre maximal d'envois pour une même alerte
                $t->timestamps();
                $t->unique(['project_id', 'category'], 'alert_rules_category_unique');
            });
        }
        if (!Schema::hasTable('alert_dispatches')) {
            Schema::create('alert_dispatches', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->string('category', 20);
                $t->string('alert_key', 190); // identifie une alerte : ex. GARANTIE:12
                $t->string('subject', 255);
                $t->text('recipients');
                $t->string('status', 10); // ENVOYE | ECHEC
                $t->string('message', 1000)->nullable();
                $t->string('trigger', 10); // AUTO | MANUEL
                $t->unsignedBigInteger('sent_by')->nullable();
                $t->timestamps();
                $t->index(['project_id', 'alert_key', 'status'], 'alert_dispatches_key_idx');
            });
        }

        // Reprise des destinataires SMTP existants (toutes catégories) et d'une séquence par défaut par projet.
        foreach (DB::table('projects')->pluck('id') as $pid) {
            $s = Schema::hasTable('smtp_settings') ? DB::table('smtp_settings')->where('project_id', $pid)->first() : null;
            foreach ($s ? [$s->recipient1 ?? null, $s->recipient2 ?? null, $s->recipient3 ?? null, $s->recipient4 ?? null] : [] as $email) {
                $email = strtolower(trim((string) $email));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || DB::table('alert_recipients')->where('project_id', $pid)->where('email', $email)->exists()) continue;
                DB::table('alert_recipients')->insert(['project_id' => $pid, 'email' => $email, 'categories' => implode(',', self::CATEGORIES), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (self::CATEGORIES as $cat) {
                if (!DB::table('alert_rules')->where('project_id', $pid)->where('category', $cat)->exists()) {
                    DB::table('alert_rules')->insert(['project_id' => $pid, 'category' => $cat, 'is_enabled' => true, 'interval_days' => 7, 'max_sends' => 3, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Volontairement non destructif : états figés et historique des envois sont des pièces de suivi (TDR §3).
    }
};
