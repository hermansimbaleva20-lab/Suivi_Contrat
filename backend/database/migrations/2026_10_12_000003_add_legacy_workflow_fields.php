<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (Schema::hasTable('companies') && !Schema::hasColumn('companies','observation')) { Schema::table('companies', function(Blueprint $table){ $table->text('observation')->nullable(); }); }
  if (Schema::hasTable('progress_entries') && !Schema::hasColumn('progress_entries','realization_physical')) {
   Schema::table('progress_entries', function(Blueprint $table){ $table->text('realization_physical')->nullable()->after('progress'); });
  }
  if (!Schema::hasTable('password_reset_requests')) {
   Schema::create('password_reset_requests',function(Blueprint $table){$table->id();$table->unsignedBigInteger('project_id')->index();$table->string('email');$table->timestamp('requested_at')->nullable();$table->boolean('is_read_by_admin')->default(false);$table->timestamps();$table->index(['project_id','is_read_by_admin']);});
  }
  if (!Schema::hasTable('smtp_settings')) {
   Schema::create('smtp_settings', function(Blueprint $table){$table->id();$table->unsignedBigInteger('project_id')->unique();$table->string('host');$table->unsignedInteger('port')->default(587);$table->string('sender_email');$table->text('password')->nullable();$table->boolean('use_ssl')->default(false);$table->string('recipient1')->nullable();$table->string('recipient2')->nullable();$table->string('recipient3')->nullable();$table->string('recipient4')->nullable();$table->timestamps();});
  }
  if (!Schema::hasTable('contract_documents')) {
   Schema::create('contract_documents', function(Blueprint $table){
    $table->id(); $table->unsignedBigInteger('contract_id')->index();
    $table->string('original_name'); $table->string('storage_path'); $table->string('mime_type',150)->nullable();
    $table->unsignedBigInteger('file_size')->default(0); $table->unsignedBigInteger('uploaded_by')->nullable();
    $table->timestamps(); $table->index(['contract_id','created_at']);
   });
  }
 } public function down(): void {
  Schema::dropIfExists('contract_documents'); Schema::dropIfExists('smtp_settings'); Schema::dropIfExists('password_reset_requests');
  if (Schema::hasTable('companies') && Schema::hasColumn('companies','observation')) Schema::table('companies',function(Blueprint $table){$table->dropColumn('observation');});
  if (Schema::hasTable('progress_entries') && Schema::hasColumn('progress_entries','realization_physical')) Schema::table('progress_entries',function(Blueprint $table){$table->dropColumn('realization_physical');});
 }
};
