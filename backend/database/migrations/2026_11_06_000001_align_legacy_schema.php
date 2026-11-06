<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Alignement des bases issues de l'application C# sur le schéma des migrations (décision du 06/11/2026), et des migrations sur
 * les règles que ces bases appliquaient déjà :
 *  - clés étrangères absentes des anciennes tables : sans elles, supprimer un contrat laissait ses sites, paiements, avancements
 *    et garanties orphelins (ContractController::destroy compte sur les suppressions en cascade) ;
 *  - une entreprise par nom dans un projet et une garantie par contrat (déjà contrôlées par l'application et prévues par
 *    App\Support\Structure) : index uniques ;
 *  - index du journal d'audit par action et des documents par contrat ; colonne users.name, facultative.
 * Une clé n'est posée que si aucune ligne ne la contredit, un index unique que s'il n'y a pas de doublon : sinon l'étape est
 * sautée et signalée dans le journal Laravel, pour être corrigée avant de relancer « php artisan migrate ». Additive et relançable.
 */
return new class extends Migration
{
    /** [table, colonne, table parente, comportement à la suppression du parent] */
    private const FOREIGN_KEYS = [
        ['alerts', 'contract_id', 'contracts', 'set null'],
        ['alerts', 'project_id', 'projects', 'cascade'],
        ['audit_logs', 'user_id', 'users', 'set null'],
        ['companies', 'project_id', 'projects', 'cascade'],
        ['contract_amendments', 'contract_id', 'contracts', 'cascade'],
        ['contract_payments', 'contract_id', 'contracts', 'cascade'],
        ['contract_sites', 'contract_id', 'contracts', 'cascade'],
        ['contracts', 'company_id', 'companies', null],
        ['contracts', 'phase_id', 'project_phases', null],
        ['contracts', 'project_id', 'projects', 'cascade'],
        ['guarantee_extensions', 'performance_guarantee_id', 'performance_guarantees', 'cascade'],
        ['performance_guarantees', 'company_id', 'companies', null],
        ['performance_guarantees', 'contract_id', 'contracts', 'cascade'],
        ['performance_guarantees', 'project_id', 'projects', 'cascade'],
        ['progress_entries', 'contract_id', 'contracts', 'cascade'],
        ['progress_entries', 'contract_site_id', 'contract_sites', 'cascade'],
        ['project_phases', 'project_id', 'projects', 'cascade'],
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('users', 'name')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('name', 191)->nullable()->after('id'));
        }

        // Index uniques avant les clés étrangères : la clé sur project_id (contract_id) s'appuie ensuite dessus.
        $this->uniqueReplacing('companies', ['project_id', 'name'], 'companies_project_id_name_unique', 'companies_project_id_name_index');
        $this->uniqueReplacing('performance_guarantees', ['contract_id'], 'performance_guarantees_contract_id_unique', 'performance_guarantees_contract_id_foreign');

        if (!Schema::hasIndex('audit_logs', 'audit_logs_action_index')) {
            Schema::table('audit_logs', fn (Blueprint $t) => $t->index('action', 'audit_logs_action_index'));
        }
        if (!Schema::hasIndex('contract_documents', 'contract_documents_contract_id_index')) {
            Schema::table('contract_documents', fn (Blueprint $t) => $t->index('contract_id', 'contract_documents_contract_id_index'));
        }
        if (!Schema::hasIndex('contract_documents', 'contract_documents_contract_id_created_at_index')) {
            Schema::table('contract_documents', fn (Blueprint $t) => $t->index(['contract_id', 'created_at'], 'contract_documents_contract_id_created_at_index'));
        }
        // Index de l'ancienne clé, redondant une fois le précédent en place : la clé s'appuie dessus (MySQL refuserait sinon).
        if (Schema::hasIndex('contract_documents', 'contract_documents_contract_id_foreign')) {
            Schema::table('contract_documents', fn (Blueprint $t) => $t->dropIndex('contract_documents_contract_id_foreign'));
        }

        foreach (self::FOREIGN_KEYS as [$table, $column, $parent, $onDelete]) {
            if ($this->hasForeignKey($table, $column, $parent)) continue;
            $orphans = DB::table("{$table} as c")->leftJoin("{$parent} as p", 'p.id', '=', "c.{$column}")->whereNotNull("c.{$column}")->whereNull('p.id')->count();
            if ($orphans) {
                Log::warning("Alignement du schéma : clé {$table}.{$column} → {$parent} non posée, {$orphans} ligne(s) orpheline(s).");
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table, $column, $parent, $onDelete) {
                $key = $t->foreign($column, "{$table}_{$column}_foreign")->references('id')->on($parent);
                if ($onDelete) $key->onDelete($onDelete);
            });
        }
    }

    /** Une clé de cette colonne vers ce parent, quel que soit son nom (une base peut l'avoir créée sous un autre). */
    private function hasForeignKey(string $table, string $column, string $parent): bool
    {
        foreach (Schema::getForeignKeys($table) as $fk) {
            if ($fk['columns'] === [$column] && $fk['foreign_table'] === $parent) return true;
        }
        return false;
    }

    /** Pose l'index unique s'il n'y a pas de doublon, puis retire l'index simple qu'il rend inutile. */
    private function uniqueReplacing(string $table, array $columns, string $unique, string $plain): void
    {
        if (!Schema::hasIndex($table, $unique)) {
            $duplicates = DB::table($table)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->get()->count();
            if ($duplicates) {
                Log::warning("Alignement du schéma : index unique {$unique} non posé, {$duplicates} doublon(s) dans {$table}.");
                return;
            }
            Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $unique));
        }
        if (Schema::hasIndex($table, $plain)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($plain));
        }
    }

    public function down(): void
    {
        // Pas de retour arrière : ces clés et index protègent des données existantes.
    }
};
