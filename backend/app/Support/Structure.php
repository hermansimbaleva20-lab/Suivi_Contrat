<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Structure « propre » de la V9 : toutes les tables métier, VIDES.
 * Utilisée par la commande « php artisan prise:reset-structure » (les utilisateurs sont conservés).
 */
class Structure
{
    /** Tables métier supprimées puis recréées vides (ordre : enfants avant parents). */
    public const BUSINESS_TABLES = [
        'user_notifications', 'report_snapshots', 'alert_dispatches', 'contract_advances', 'contract_decomptes', 'fleet_logs', 'fleet_maintenances', 'fleet_insurances', 'fleet_assets', 'stock_movements', 'stock_articles', 'guarantee_extensions', 'performance_guarantees', 'contract_renewals', 'contract_amendments', 'contract_documents', 'contract_payments',
        'progress_entries', 'contract_sites', 'contracts', 'companies', 'alerts', 'work_types', 'password_reset_requests',
        'user_reset_tokens', 'audit_logs',
    ];

    /** Tables conservées telles quelles : comptes et configuration. */
    public const KEPT_TABLES = ['users', 'personal_access_tokens', 'projects', 'project_phases', 'smtp_settings', 'provinces', 'organizational_units', 'positions', 'alert_recipients', 'alert_rules', 'alert_templates', 'ai_settings'];

    public static function dropBusinessTables(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::BUSINESS_TABLES as $table) Schema::dropIfExists($table);
        Schema::enableForeignKeyConstraints();
    }

    public static function createBusinessTables(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('address')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->text('observation')->nullable();
            $t->string('guarantee_bank')->nullable();
            $t->string('guarantee_account_number')->nullable();
            $t->text('guarantee_observation')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'name']);
        });

        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('phase_id')->constrained('project_phases');
            $t->foreignId('company_id')->constrained('companies');
            $t->string('reference');
            $t->string('title')->nullable();
            $t->string('province')->nullable();
            $t->string('category', 80)->nullable();
            $t->decimal('amount', 15, 2);
            $t->date('signature_date')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->unsignedInteger('duration_months')->nullable();
            $t->decimal('initial_financing_rate', 5, 2)->default(0);
            $t->text('activities')->nullable();
            $t->text('work_type_text')->nullable();
            $t->boolean('completed')->default(false);
            $t->date('termination_date')->nullable();
            $t->text('termination_reason')->nullable();
            $t->unsignedInteger('renewal_no')->default(0);
            $t->decimal('renewed_amount', 15, 2)->default(0);
            $t->string('goods_procedure', 20)->nullable(); // BAD | NATIONALE | FOURNITURES_BUREAU (contrats de biens)
            $t->string('goods_origin', 10)->nullable(); // ETRANGER | LOCAL
            $t->text('payment_schedule')->nullable(); // échéancier indicatif (JSON)
            $t->decimal('delay_penalty_rate', 6, 3)->nullable(); // % par semaine de retard (procédure nationale)
            $t->decimal('delay_penalty_cap', 5, 2)->nullable(); // plafond, en % du montant du marché
            $t->decimal('goods_min_amount', 15, 2)->nullable(); // fournitures de bureau : clause du contrat, montants d'une commande
            $t->decimal('goods_max_amount', 15, 2)->nullable();
            $t->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete(); // unité d'un contrat de fournitures de bureau
            $t->timestamps();
            $t->unique(['project_id', 'reference'], 'contracts_project_reference_unique');
            $t->index(['project_id', 'phase_id']);
        });

        Schema::create('alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $t->string('type');
            $t->text('message');
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('contract_sites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->string('province');
            $t->string('work_type')->nullable();
            $t->string('name');
            $t->text('description')->nullable();
            $t->decimal('quantity', 15, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('progress_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contract_site_id')->nullable()->constrained('contract_sites')->cascadeOnDelete();
            $t->date('situation_date');
            $t->decimal('progress', 6, 2);
            $t->text('realization_physical')->nullable();
            $t->decimal('paid_amount', 15, 2)->default(0);
            $t->text('observation')->nullable();
            $t->timestamps();
            $t->index(['contract_site_id', 'situation_date']);
        });

        Schema::create('contract_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->decimal('amount', 15, 2);
            $t->string('reference')->nullable();
            $t->text('observation')->nullable();
            $t->timestamps();
        });

        Schema::create('contract_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->string('original_name');
            $t->string('storage_path');
            $t->string('mime_type', 150)->nullable();
            $t->unsignedBigInteger('file_size')->default(0);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();            $t->index('contract_id', 'contract_documents_contract_id_index');
            $t->index(['contract_id', 'created_at'], 'contract_documents_contract_id_created_at_index');
        });

        Schema::create('performance_guarantees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('bank_name');
            $t->string('account_number')->nullable();
            $t->decimal('rate', 5, 2)->default(5);
            $t->decimal('amount', 15, 2);
            $t->date('start_date');
            $t->date('end_date');
            $t->text('observation')->nullable();
            $t->dateTime('alerts_paused_at')->nullable();
            $t->unsignedBigInteger('alerts_paused_by')->nullable();
            $t->string('alerts_pause_reason', 500)->nullable();
            $t->unsignedTinyInteger('alerts_per_week')->nullable();
            $t->timestamps();
            $t->unique('contract_id');
        });

        Schema::create('guarantee_extensions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('performance_guarantee_id')->constrained('performance_guarantees')->cascadeOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->enum('type', ['RENOUVELLEMENT', 'PROLONGATION'])->default('PROLONGATION');
            $t->text('reason');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('contract_amendments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->integer('duration_days')->default(0);
            $t->unsignedInteger('added_months')->default(0);
            $t->date('previous_end_date')->nullable();
            $t->date('new_end_date')->nullable();
            $t->text('reason');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('contract_renewals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('renewal_no');
            $t->date('date');
            $t->string('previous_reference');
            $t->string('new_reference');
            $t->decimal('added_amount', 15, 2)->default(0);
            $t->decimal('previous_amount', 15, 2);
            $t->date('previous_end_date')->nullable();
            $t->date('period_start');
            $t->date('new_end_date');
            $t->unsignedInteger('months');
            $t->unsignedInteger('last_line_no')->default(0);
            $t->text('reason');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['contract_id', 'renewal_no'], 'contract_renewals_no_unique');
        });

        Schema::create('work_types', function (Blueprint $t) {
            $t->id();
                $t->unsignedBigInteger('project_id')->index();
          $t->string('category', 80);
        $t->string('name', 100);
        $t->boolean('is_custom')->default(true);
        $t->timestamps();
        $t->unique(['project_id', 'category', 'name'], 'work_types_unique');
        });

        Schema::create('password_reset_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('email');
            $t->timestamp('requested_at')->nullable();
            $t->boolean('is_read_by_admin')->default(false);
            $t->string('status', 200)->default('Lien envoyé');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'is_read_by_admin']);
        });

        Schema::create('user_reset_tokens', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('token_hash', 100)->index();
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('project_id')->nullable()->index();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->string('entity_name');
            $t->string('entity_id')->nullable();
            $t->text('details')->nullable();
            $t->string('machine_name')->nullable();
            $t->unsignedBigInteger('organizational_unit_id')->nullable()->index();
            $t->longText('old_values')->nullable();
            $t->longText('new_values')->nullable();
            $t->timestamps();
            $t->index('action');
        });

        // Logistique — stocks (TDR §6) : catalogue du projet et fiches de stock par unité
        Schema::create('stock_articles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Chaque unité tient ses propres articles
            $t->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->restrictOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete(); // articles importés d'un contrat
            $t->unsignedInteger('number');
            $t->string('designation', 255);
            $t->string('unit', 40);
            $t->decimal('pack_size', 10, 2)->nullable();
            $t->string('pack_unit', 40)->nullable();
            $t->decimal('unit_price', 18, 2)->default(0);
            $t->decimal('alert_threshold', 14, 2)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['project_id', 'organizational_unit_id', 'number'], 'stock_articles_unit_number_unique');
            $t->index(['project_id', 'designation'], 'stock_articles_project_designation_idx'); // unique par unité, contrôlé par l'API
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
            $t->foreignId('stock_article_id')->constrained('stock_articles')->restrictOnDelete();
            $t->unsignedInteger('operation_no')->nullable();
            $t->date('movement_date');
            $t->string('type', 10);
            $t->decimal('quantity', 14, 2);
            $t->string('origin_destination', 190);
            $t->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $t->string('reference', 100)->nullable();
            $t->string('observation', 500)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['project_id', 'organizational_unit_id', 'stock_article_id', 'movement_date'], 'stock_movements_card_idx');
        });

        // Rapports figés et historique des envois d'alertes
        Schema::create('report_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('phase_id')->nullable();
            $t->string('period_type', 12);
            $t->string('label', 60);
            $t->date('period_start');
            $t->date('period_end');
            $t->longText('data');
            $t->string('comment', 2000)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'phase_id', 'label'], 'report_snapshots_label_unique');
        });
        Schema::create('alert_dispatches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('category', 20);
            $t->string('alert_key', 190);
            $t->string('subject', 255);
            $t->text('recipients');
            $t->string('status', 10);
            $t->string('message', 1000)->nullable();
            $t->string('trigger', 10);
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->timestamps();
            $t->unsignedBigInteger('contract_id')->nullable();
            $t->unsignedBigInteger('performance_guarantee_id')->nullable();
            $t->string('guarantee_number', 190)->nullable();
            $t->string('message_type', 20)->nullable();
            $t->text('body')->nullable();
            $t->index(['project_id', 'alert_key', 'status'], 'alert_dispatches_key_idx');
            $t->index(['project_id', 'contract_id'], 'alert_dispatches_contract_idx');
        });

        // Décomptes (situation du contrat)
        Schema::create('contract_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $t->date('request_date');
            $t->string('request_reference', 120);
            $t->decimal('rate', 5, 2);
            $t->decimal('amount', 15, 2);
            $t->string('guarantee_bank', 191)->nullable(); // garantie de restitution : BAD et nationale
            $t->string('guarantee_number', 120)->nullable();
            $t->decimal('guarantee_amount', 15, 2)->nullable();
            $t->date('guarantee_end_date')->nullable();
            $t->string('status', 12)->default('DEMANDEE'); // DEMANDEE | PAYEE | REJETEE
            $t->date('paid_date')->nullable();
            $t->string('payment_reference', 120)->nullable();
            $t->string('decision_comment', 500)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamps();
            $t->index(['contract_id', 'status'], 'contract_advances_status_idx');
        });

        Schema::create('contract_decomptes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $t->unsignedInteger('line_no');
            $t->string('number', 60);
            $t->string('kind', 12)->default('DECOMPTE'); // DECOMPTE | LIBERATION (ligne « RETENUE »)
            $t->date('operation_date');
            $t->decimal('gross_amount', 15, 2);
            $t->decimal('advance_rate', 6, 4)->default(0);
            $t->decimal('guarantee_rate', 6, 4)->default(0);
            $t->string('status', 12)->default('EN_ATTENTE');
            $t->string('observation', 500)->nullable();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->date('decision_date')->nullable(); // date de l'opération de validation ou de rejet, saisie par le comptable
            $t->string('decision_comment', 500)->nullable();
            $t->boolean('is_final')->default(false); // dernier décompte (réception), procédure nationale
            $t->date('reception_date')->nullable();
            $t->unsignedInteger('penalty_weeks')->default(0);
            $t->decimal('penalty_amount', 15, 2)->default(0);
            $t->timestamps();
            $t->unique(['contract_id', 'line_no'], 'decomptes_contract_line_unique');
            $t->index(['project_id', 'status'], 'decomptes_project_status_idx');
        });
        // Paiement créé par la validation d'un décompte (non modifiable dans Paiements)
        Schema::table('contract_payments', fn (Blueprint $t) => $t->foreignId('decompte_id')->nullable()->unique()->constrained('contract_decomptes')->nullOnDelete());

        // Charroi et groupes électrogènes (TDR §7, §8) : parc, carnets de bord, entretiens, assurances
        Schema::create('fleet_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
            $t->string('kind', 10);
            $t->string('name', 150);
            $t->string('registration', 40)->nullable();
            $t->decimal('tank_capacity', 8, 2)->default(120);
            $t->decimal('consumption_rate', 8, 3)->nullable();
            $t->decimal('fuel_price', 8, 3)->default(1.37);
            $t->decimal('fuel_alert_level', 8, 2)->nullable();
            $t->unsignedInteger('maintenance_interval');
            $t->unsignedInteger('maintenance_warning');
            $t->decimal('initial_meter', 12, 1)->default(0);
            $t->decimal('maintenance_last_meter', 12, 1)->nullable(); // programmation : compteur du dernier entretien
            $t->decimal('maintenance_next_meter', 12, 1)->nullable(); // programmation : compteur du prochain entretien
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['project_id', 'name'], 'fleet_assets_project_name_unique');
        });

        Schema::create('fleet_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
            $t->foreignId('fleet_asset_id')->constrained('fleet_assets')->restrictOnDelete();
            $t->date('log_date');
            $t->string('requester', 150)->nullable();
            $t->string('destination', 190)->nullable();
            $t->string('purpose', 255)->nullable();
            $t->string('departure_time', 5)->nullable();
            $t->string('arrival_time', 5)->nullable();
            $t->decimal('meter_start', 12, 1);
            $t->decimal('meter_end', 12, 1);
            $t->decimal('fuel_level_before', 8, 2)->nullable();
            $t->decimal('fuel_purchased', 8, 2)->nullable();
            $t->decimal('fuel_price', 8, 3)->nullable();
            $t->string('lubricant', 50)->nullable()->default('PLEIN');
            $t->string('observation', 500)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable(); // HRAF ou comptable désigné pour l'autorisation
            $t->unsignedBigInteger('authorized_by')->nullable();
            $t->timestamp('authorized_at')->nullable();
            $t->unsignedBigInteger('objected_by')->nullable();
            $t->timestamp('objected_at')->nullable();
            $t->string('objection_reason', 500)->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['project_id', 'fleet_asset_id', 'log_date'], 'fleet_logs_asset_date_idx');
            $t->index(['project_id', 'organizational_unit_id', 'log_date'], 'fleet_logs_unit_date_idx');
            $t->index(['assigned_to', 'authorized_at'], 'fleet_logs_assignee_idx');
        });

        Schema::create('fleet_maintenances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
            $t->foreignId('fleet_asset_id')->constrained('fleet_assets')->restrictOnDelete();
            $t->date('maintenance_date');
            $t->decimal('next_meter', 12, 1)->nullable(); // compteur du prochain entretien
            $t->decimal('meter_reading', 12, 1);
            $t->string('designation', 255);
            $t->decimal('cost', 14, 2)->nullable();
            $t->string('provider', 190)->nullable();
            $t->string('observation', 500)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'fleet_asset_id', 'maintenance_date'], 'fleet_maint_asset_date_idx');
        });

        Schema::create('fleet_insurances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('organizational_unit_id')->constrained('organizational_units')->restrictOnDelete();
            $t->foreignId('fleet_asset_id')->constrained('fleet_assets')->restrictOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('designation', 255);
            $t->decimal('cost', 14, 2)->nullable();
            $t->string('observation', 500)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'fleet_asset_id', 'end_date'], 'fleet_ins_asset_end_idx');
        });

        Schema::create('user_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 40);
            $t->string('title', 190);
            $t->string('body', 500)->nullable();
            $t->string('link', 190)->nullable();
            $t->string('entity_name', 60)->nullable();
            $t->string('entity_id', 40)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at'], 'user_notifications_user_read_idx');
            $t->index(['entity_name', 'entity_id'], 'user_notifications_entity_idx');
        });
    }

    /**
     * Données de configuration indispensables au fonctionnement (aucune donnée métier) :
     * projets, une phase courante par projet, provinces, catalogue d'ouvrages de départ, une ligne SMTP par projet.
     */
    public static function seedReference(): void
    {
        $now = now();
        $names = [
            'PRISE' => 'Projet de Renforcement des Infrastructures Socio-Économiques dans la Zone Centre de la RDC',
            'PREDIRE' => 'Programme Régional d’Appui au Développement des Infrastructures et de Valorisation des Ressources en Eau Transfrontalières entre la RCA et la RDC',
        ];
        foreach ($names as $code => $name) {
            if (!DB::table('projects')->where('code', $code)->exists()) {
                DB::table('projects')->insert(['code' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach (DB::table('projects')->get() as $p) {
            if (!DB::table('project_phases')->where('project_id', $p->id)->exists()) {
                DB::table('project_phases')->insert([
                    'project_id' => $p->id, 'number' => $p->code === 'PRISE' ? 2 : 1, 'name' => $p->code === 'PRISE' ? 'Phase 2' : 'Phase 1',
                    'description' => null, 'is_current' => true, 'is_archived' => false, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } elseif (!DB::table('project_phases')->where('project_id', $p->id)->where('is_current', true)->exists()) {
                $first = DB::table('project_phases')->where('project_id', $p->id)->where('is_archived', false)->orderBy('number')->first()
                    ?: DB::table('project_phases')->where('project_id', $p->id)->orderBy('number')->first();
                DB::table('project_phases')->where('id', $first->id)->update(['is_current' => true, 'is_archived' => false]);
            }
            foreach (['Système d’AEP', 'École primaire', 'Centre de santé', 'Latrine publique', 'Espace à vivre'] as $wt) {
                DB::table('work_types')->insertOrIgnore(['project_id' => $p->id, 'category' => 'TRAVAUX', 'name' => $wt, 'is_custom' => $wt !== 'Système d’AEP', 'created_at' => $now, 'updated_at' => $now]);
            }
            if (Schema::hasTable('smtp_settings') && !DB::table('smtp_settings')->where('project_id', $p->id)->exists()) {
                DB::table('smtp_settings')->insert(['project_id' => $p->id, 'host' => 'smtp.gmail.com', 'port' => 587, 'sender_email' => '', 'use_ssl' => false, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        if (!Schema::hasTable('provinces')) {
            Schema::create('provinces', function (Blueprint $t) {
                $t->id();
                $t->string('name', 150)->unique();
                $t->timestamps();
            });
        }
        foreach (['Kinshasa', 'Kongo Central', 'Kwango', 'Kwilu', 'Mai-Ndombe', 'Équateur', 'Mongala', 'Nord-Ubangi', 'Sud-Ubangi', 'Tshuapa', 'Tshopo', 'Bas-Uele', 'Haut-Uele',
            'Ituri', 'Nord-Kivu', 'Sud-Kivu', 'Maniema', 'Tanganyika', 'Haut-Lomami', 'Lualaba', 'Haut-Katanga', 'Kasaï', 'Kasaï Central', 'Lomami', 'Sankuru', 'Kasaï Oriental'] as $province) {
            if (Schema::hasColumn('provinces', 'name')) DB::table('provinces')->insertOrIgnore(['name' => $province, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
