<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (Schema::hasTable('contracts')) Schema::table('contracts', function (Blueprint $t) {
   if (!Schema::hasColumn('contracts','category')) $t->string('category',80)->nullable();
   if (!Schema::hasColumn('contracts','signature_date')) $t->date('signature_date')->nullable();
   if (!Schema::hasColumn('contracts','duration_months')) $t->unsignedInteger('duration_months')->nullable();
   if (!Schema::hasColumn('contracts','activities')) $t->text('activities')->nullable();
  });
 }
 public function down(): void {
  // Migration additive et volontairement non destructive : aucun champ ni donnée n'est supprimé.
 }
};
