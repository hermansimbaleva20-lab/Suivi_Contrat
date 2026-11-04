<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V9 — Alignement sur l'application C# d'origine (PRISE.Web).
 * Migration ADDITIVE et NON DESTRUCTIVE : aucune table ni donnée existante n'est supprimée.
 * Elle peut être relancée sans risque (chaque ajout est précédé d'un contrôle d'existence).
 */
return new class extends Migration {
    public function up(): void
    {
        // ---------------------------------------------------------------- contracts
        Schema::table('contracts', function (Blueprint $t) {
            if (!Schema::hasColumn('contracts', 'completed')) $t->boolean('completed')->default(false);
            if (!Schema::hasColumn('contracts', 'work_type_text')) $t->text('work_type_text')->nullable();
        });
        // L'intitulé n'existe pas dans l'application d'origine : il devient facultatif.
        try { Schema::table('contracts', fn (Blueprint $t) => $t->string('title')->nullable()->change()); } catch (\Throwable $e) {}
        // Le numéro de contrat est unique PAR PROJET (et non plus globalement).
        try { Schema::table('contracts', fn (Blueprint $t) => $t->dropUnique(['reference'])); } catch (\Throwable $e) {}
        try { Schema::table('contracts', fn (Blueprint $t) => $t->unique(['project_id', 'reference'], 'contracts_project_reference_unique')); } catch (\Throwable $e) {}

        // ---------------------------------------------------------------- progress_entries : saisie possible sans site
        try { Schema::table('progress_entries', fn (Blueprint $t) => $t->unsignedBigInteger('contract_site_id')->nullable()->change()); } catch (\Throwable $e) {}
        Schema::table('progress_entries', function (Blueprint $t) {
            if (!Schema::hasColumn('progress_entries', 'realization_physical')) $t->text('realization_physical')->nullable();
        });

        // ---------------------------------------------------------------- avenants
        Schema::table('contract_amendments', function (Blueprint $t) {
            if (!Schema::hasColumn('contract_amendments', 'added_months')) $t->unsignedInteger('added_months')->default(0);
            if (!Schema::hasColumn('contract_amendments', 'previous_end_date')) $t->date('previous_end_date')->nullable();
            if (!Schema::hasColumn('contract_amendments', 'created_by')) $t->unsignedBigInteger('created_by')->nullable();
        });
        DB::table('contract_amendments')->where('added_months', 0)->where('duration_days', '>', 0)
            ->update(['added_months' => DB::raw('GREATEST(1, ROUND(duration_days / 30))')]);

        // ---------------------------------------------------------------- garanties : auteur du mouvement
        Schema::table('guarantee_extensions', function (Blueprint $t) {
            if (!Schema::hasColumn('guarantee_extensions', 'created_by')) $t->unsignedBigInteger('created_by')->nullable();
        });

        // ---------------------------------------------------------------- audit par projet
        Schema::table('audit_logs', function (Blueprint $t) {
            if (!Schema::hasColumn('audit_logs', 'project_id')) $t->unsignedBigInteger('project_id')->nullable()->index();
        });
        DB::statement('UPDATE audit_logs a JOIN users u ON u.id = a.user_id SET a.project_id = u.project_id WHERE a.project_id IS NULL');

        // ---------------------------------------------------------------- réinitialisation du mot de passe par jeton
        if (Schema::hasTable('password_reset_requests')) {
            Schema::table('password_reset_requests', function (Blueprint $t) {
                if (!Schema::hasColumn('password_reset_requests', 'user_id')) $t->unsignedBigInteger('user_id')->nullable();
                if (!Schema::hasColumn('password_reset_requests', 'status')) $t->string('status', 200)->default('Lien envoyé');
                if (!Schema::hasColumn('password_reset_requests', 'processed_at')) $t->timestamp('processed_at')->nullable();
            });
        }
        if (!Schema::hasTable('user_reset_tokens')) {
            Schema::create('user_reset_tokens', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('project_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('token_hash', 100)->index();
                $t->timestamp('expires_at');
                $t->timestamp('used_at')->nullable();
                $t->timestamps();
            });
        }

        // ---------------------------------------------------------------- référentiels
        if (!Schema::hasTable('work_types')) {
            Schema::create('work_types', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('project_id')->index();
                $t->string('category', 80);
                $t->string('name', 100);
                $t->boolean('is_custom')->default(true);
                $t->timestamps();
                $t->unique(['project_id', 'category', 'name'], 'work_types_unique');
            });
            // Catalogue de départ proposé aux deux projets.
            $now = now();
            foreach (DB::table('projects')->pluck('id') as $projectId) {
                foreach (['Système d’AEP', 'École primaire', 'Centre de santé', 'Latrine publique', 'Espace à vivre'] as $name) {
                    DB::table('work_types')->insertOrIgnore(['project_id' => $projectId, 'category' => 'TRAVAUX', 'name' => $name, 'is_custom' => $name !== 'Système d’AEP', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }

        if (!Schema::hasTable('provinces')) {
            Schema::create('provinces', function (Blueprint $t) {
                $t->id();
                $t->string('name', 150)->unique();
                $t->timestamps();
            });
            $now = now();
            foreach (['Kinshasa', 'Kongo Central', 'Kwango', 'Kwilu', 'Mai-Ndombe', 'Équateur', 'Mongala', 'Nord-Ubangi', 'Sud-Ubangi', 'Tshuapa', 'Tshopo',
                'Bas-Uele', 'Haut-Uele', 'Ituri', 'Nord-Kivu', 'Sud-Kivu', 'Maniema', 'Tanganyika', 'Haut-Lomami', 'Lualaba', 'Haut-Katanga', 'Kasaï',
                'Kasaï Central', 'Lomami', 'Sankuru', 'Kasaï Oriental'] as $name) {
                DB::table('provinces')->insertOrIgnore(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // ---------------------------------------------------------------- paramètres SMTP : une ligne par projet
        if (Schema::hasTable('smtp_settings')) {
            foreach (DB::table('projects')->pluck('id') as $projectId) {
                if (!DB::table('smtp_settings')->where('project_id', $projectId)->exists()) {
                    DB::table('smtp_settings')->insert(['project_id' => $projectId, 'host' => 'smtp.gmail.com', 'port' => 587, 'sender_email' => '', 'use_ssl' => false, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        // ---------------------------------------------------------------- super-administrateur
        DB::table('users')->where('email', 'hermansimbaleva20@gmail.com')->update(['is_super_admin' => true, 'is_active' => true, 'is_pending' => false]);
    }

    public function down(): void
    {
        // Volontairement non destructif : aucune donnée n'est supprimée.
    }
};
