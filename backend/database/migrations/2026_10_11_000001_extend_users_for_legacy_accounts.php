<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users','project_id')) $t->unsignedBigInteger('project_id')->nullable()->after('id');
            if (!Schema::hasColumn('users','full_name')) $t->string('full_name')->nullable()->after('project_id');
            if (!Schema::hasColumn('users','password_hash')) $t->string('password_hash',500)->nullable();
            if (!Schema::hasColumn('users','password_salt')) $t->string('password_salt',500)->nullable();
            if (!Schema::hasColumn('users','is_pending')) $t->boolean('is_pending')->default(false);
            if (!Schema::hasColumn('users','can_manage_settings')) $t->boolean('can_manage_settings')->default(false);
            if (!Schema::hasColumn('users','can_manage_users')) $t->boolean('can_manage_users')->default(false);
            if (!Schema::hasColumn('users','can_delete_contracts')) $t->boolean('can_delete_contracts')->default(false);
            if (!Schema::hasColumn('users','can_manage_contracts')) $t->boolean('can_manage_contracts')->default(false);
            if (!Schema::hasColumn('users','can_manage_companies')) $t->boolean('can_manage_companies')->default(false);
            if (!Schema::hasColumn('users','can_manage_progress')) $t->boolean('can_manage_progress')->default(false);
            if (!Schema::hasColumn('users','can_manage_payments')) $t->boolean('can_manage_payments')->default(false);
            if (!Schema::hasColumn('users','can_view_reports')) $t->boolean('can_view_reports')->default(true);
            if (!Schema::hasColumn('users','can_view_alerts')) $t->boolean('can_view_alerts')->default(true);
            if (!Schema::hasColumn('users','can_view_situation')) $t->boolean('can_view_situation')->default(true);
            if (!Schema::hasColumn('users','is_super_admin')) $t->boolean('is_super_admin')->default(false);
            if (!Schema::hasColumn('users','failed_login_count')) $t->unsignedInteger('failed_login_count')->default(0);
            if (!Schema::hasColumn('users','lockout_end')) $t->timestamp('lockout_end')->nullable();
            if (!Schema::hasColumn('users','last_login_at')) $t->timestamp('last_login_at')->nullable();
        });
        // Preserve existing accounts while allowing one email to exist in both projects.
        try { Schema::table('users', fn(Blueprint $t) => $t->dropUnique(['email'])); } catch (\Throwable $e) {}
        try { Schema::table('users', fn(Blueprint $t) => $t->unique(['email','project_id'], 'users_email_project_unique')); } catch (\Throwable $e) {}
        DB::table('users')->whereNull('full_name')->update(['full_name'=>DB::raw('name')]);
        DB::table('users')->whereNull('project_id')->update(['project_id'=>1]);
    }
    public function down(): void {
        // Intentionally non-destructive: user data and imported legacy credentials must not be dropped.
    }
};
