<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TDR §5 et §16 phase 2 — socle organisationnel : coordination nationale / pools, postes, affectation des utilisateurs,
 * audit avec valeurs avant/après. Migration additive et relançable : aucune donnée existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('organizational_units')) {
            Schema::create('organizational_units', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->string('type', 20); // COORDINATION | POOL
                $t->string('code', 30);
                $t->string('name', 150);
                $t->string('province', 150)->nullable();
                $t->text('description')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['project_id', 'code'], 'org_units_project_code_unique');
                $t->index(['project_id', 'type', 'is_active'], 'org_units_project_type_idx');
            });
        }

        if (!Schema::hasTable('positions')) {
            Schema::create('positions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('project_id')->constrained()->cascadeOnDelete();
                $t->string('name', 150);
                $t->text('description')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['project_id', 'name'], 'positions_project_name_unique');
            });
        }

        // Sur une base créée par les migrations, « name » est obligatoire alors que l'application n'écrit que « full_name » :
        // l'inscription échouait. Les bases issues de l'application d'origine n'ont pas cette colonne.
        if (Schema::hasColumn('users', 'name')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('name')->nullable()->change());
        }

        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'organizational_unit_id')) $t->foreignId('organizational_unit_id')->nullable()->constrained('organizational_units')->nullOnDelete();
            if (!Schema::hasColumn('users', 'position_id')) $t->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
        });

        Schema::table('audit_logs', function (Blueprint $t) {
            if (!Schema::hasColumn('audit_logs', 'organizational_unit_id')) $t->unsignedBigInteger('organizational_unit_id')->nullable()->index();
            if (!Schema::hasColumn('audit_logs', 'old_values')) $t->longText('old_values')->nullable();
            if (!Schema::hasColumn('audit_logs', 'new_values')) $t->longText('new_values')->nullable();
        });

        // Une coordination nationale par projet (modifiable ensuite) ; les pools sont créés par l'administration (TDR §5).
        foreach (DB::table('projects')->get() as $p) {
            if (!DB::table('organizational_units')->where('project_id', $p->id)->where('type', 'COORDINATION')->exists()) {
                DB::table('organizational_units')->insert([
                    'project_id' => $p->id, 'type' => 'COORDINATION', 'code' => 'CN', 'name' => 'Coordination nationale',
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Volontairement non destructif : les affectations et le référentiel sont conservés (TDR §3).
    }
};
