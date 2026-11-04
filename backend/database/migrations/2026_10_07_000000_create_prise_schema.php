<?php
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint; use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void {
 Schema::create('users', function (Blueprint $t) {
    $t->id();
    $t->string('name');
    $t->string('email', 191)->unique();
    $t->string('password');$t->string('function')->nullable();$t->string('project_position')->nullable();$t->string('role')->default('UTILISATEUR');$t->boolean('is_active')->default(true);$t->boolean('can_view_archives')->default(false);$t->boolean('can_manage_guarantees')->default(false);$t->rememberToken();$t->timestamps();});
 Schema::create('personal_access_tokens', function (Blueprint $t) {
    $t->id();

    $t->unsignedBigInteger('tokenable_id');
    $t->string('tokenable_type', 191);

    $t->index(
        ['tokenable_type', 'tokenable_id'],
        'personal_access_tokens_tokenable_type_tokenable_id_index'
    );

    $t->string('name');
    $t->string('token', 64)->unique();
    $t->text('abilities')->nullable();
    $t->timestamp('last_used_at')->nullable();
    $t->timestamp('expires_at')->nullable();
    $t->timestamps();
});
 Schema::create('projects',function(Blueprint $t){$t->id();$t->string('code',20)->unique();$t->string('name');$t->timestamps();});
 Schema::create('project_phases',function(Blueprint $t){$t->id();$t->foreignId('project_id')->constrained()->cascadeOnDelete();$t->unsignedInteger('number');$t->string('name');$t->text('description')->nullable();$t->boolean('is_current')->default(false);$t->boolean('is_archived')->default(false);$t->timestamps();$t->unique(['project_id','number']);});
 Schema::create('companies',function(Blueprint $t){$t->id();$t->foreignId('project_id')->constrained()->cascadeOnDelete();$t->string('name');$t->text('address')->nullable();$t->string('phone')->nullable();$t->string('email')->nullable();$t->string('guarantee_bank')->nullable();$t->string('guarantee_account_number')->nullable();$t->text('guarantee_observation')->nullable();$t->timestamps();$t->index(['project_id','name']);});
 Schema::create('contracts',function(Blueprint $t){$t->id();$t->foreignId('project_id')->constrained()->cascadeOnDelete();$t->foreignId('phase_id')->constrained('project_phases');$t->foreignId('company_id')->constrained();$t->string('reference')->unique();$t->string('title');$t->string('province')->nullable();$t->decimal('amount',15,2);$t->date('start_date')->nullable();$t->date('end_date')->nullable();$t->decimal('initial_financing_rate',5,2)->default(0);$t->date('termination_date')->nullable();$t->text('termination_reason')->nullable();$t->timestamps();$t->index(['project_id','phase_id']);});
 Schema::create('contract_sites',function(Blueprint $t){$t->id();$t->foreignId('contract_id')->constrained()->cascadeOnDelete();$t->string('province');$t->string('work_type')->nullable();$t->string('name');$t->text('description')->nullable();$t->decimal('quantity',15,2)->nullable();$t->timestamps();});
 Schema::create('progress_entries',function(Blueprint $t){$t->id();$t->foreignId('contract_id')->constrained()->cascadeOnDelete();$t->foreignId('contract_site_id')->constrained()->cascadeOnDelete();$t->date('situation_date');$t->decimal('progress',6,2);$t->decimal('paid_amount',15,2)->default(0);$t->text('observation')->nullable();$t->timestamps();$t->index(['contract_site_id','situation_date']);});
 Schema::create('contract_payments',function(Blueprint $t){$t->id();$t->foreignId('contract_id')->constrained()->cascadeOnDelete();$t->date('date');$t->decimal('amount',15,2);$t->string('reference')->nullable();$t->text('observation')->nullable();$t->timestamps();});
 Schema::create('performance_guarantees',function(Blueprint $t){$t->id();$t->foreignId('project_id')->constrained()->cascadeOnDelete();$t->foreignId('contract_id')->constrained()->cascadeOnDelete();$t->foreignId('company_id')->constrained();$t->string('bank_name');$t->string('account_number')->nullable();$t->decimal('rate',5,2)->default(5);$t->decimal('amount',15,2);$t->date('start_date');$t->date('end_date');$t->text('observation')->nullable();$t->timestamps();});
 Schema::create('guarantee_extensions',function(Blueprint $t){$t->id();$t->foreignId('performance_guarantee_id')->constrained()->cascadeOnDelete();$t->date('start_date');$t->date('end_date');$t->enum('type',['RENOUVELLEMENT','PROLONGATION'])->default('PROLONGATION');$t->text('reason');$t->timestamps();});
 Schema::create('contract_amendments',function(Blueprint $t){$t->id();$t->foreignId('contract_id')->constrained()->cascadeOnDelete();$t->date('date');$t->integer('duration_days')->default(0);$t->text('reason');$t->date('new_end_date')->nullable();$t->timestamps();});
 Schema::create('audit_logs',function(Blueprint $t){$t->id();$t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();$t->string('action');$t->string('entity_name');$t->string('entity_id')->nullable();$t->text('details')->nullable();$t->string('machine_name')->nullable();$t->timestamps();});
 Schema::create('alerts',function(Blueprint $t){$t->id();$t->foreignId('project_id')->constrained()->cascadeOnDelete();$t->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();$t->string('type');$t->text('message');$t->timestamp('sent_at')->nullable();$t->timestamp('resolved_at')->nullable();$t->timestamps();});
 Schema::create('jobs',function(Blueprint $t){$t->id();$t->string('queue')->index();$t->longText('payload');$t->unsignedTinyInteger('attempts');$t->timestamp('reserved_at')->nullable();$t->timestamp('available_at');$t->timestamp('created_at');});
 Schema::create('cache',function(Blueprint $t){$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');});
 Schema::create('cache_locks',function(Blueprint $t){$t->string('key')->primary();$t->integer('expiration');});
 }};
