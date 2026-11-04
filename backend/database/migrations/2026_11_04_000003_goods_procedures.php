<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marchés de biens (document « Procédure pour marché de biens ») : procédure BAD, nationale ou fournitures de bureau,
 * origine des biens, échéancier indicatif, pénalité de retard, et avance versée sur demande contre une garantie de restitution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            if (!Schema::hasColumn('contracts', 'goods_procedure')) $t->string('goods_procedure', 20)->nullable();
            if (!Schema::hasColumn('contracts', 'goods_origin')) $t->string('goods_origin', 10)->nullable();
            if (!Schema::hasColumn('contracts', 'payment_schedule')) $t->text('payment_schedule')->nullable();
            if (!Schema::hasColumn('contracts', 'delay_penalty_rate')) $t->decimal('delay_penalty_rate', 6, 3)->nullable();
            if (!Schema::hasColumn('contracts', 'delay_penalty_cap')) $t->decimal('delay_penalty_cap', 5, 2)->nullable();
        });
        Schema::table('contract_decomptes', function (Blueprint $t) {
            if (!Schema::hasColumn('contract_decomptes', 'is_final')) $t->boolean('is_final')->default(false);
            if (!Schema::hasColumn('contract_decomptes', 'reception_date')) $t->date('reception_date')->nullable();
            if (!Schema::hasColumn('contract_decomptes', 'penalty_weeks')) $t->unsignedInteger('penalty_weeks')->default(0);
            if (!Schema::hasColumn('contract_decomptes', 'penalty_amount')) $t->decimal('penalty_amount', 15, 2)->default(0);
        });
        if (!Schema::hasTable('contract_advances')) {
            Schema::create('contract_advances', function (Blueprint $t) {
                $t->id();
                $t->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
                $t->date('request_date');
                $t->string('request_reference', 120);
                $t->decimal('rate', 5, 2);
                $t->decimal('amount', 15, 2);
                $t->string('guarantee_bank', 191);
                $t->string('guarantee_number', 120);
                $t->decimal('guarantee_amount', 15, 2);
                $t->date('guarantee_end_date');
                $t->string('status', 12)->default('DEMANDEE'); // DEMANDEE | PAYEE | REJETEE
                $t->date('paid_date')->nullable();
                $t->string('payment_reference', 120)->nullable();
                $t->string('decision_comment', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamps();
                $t->index(['contract_id', 'status'], 'contract_advances_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_advances');
        Schema::table('contract_decomptes', fn (Blueprint $t) => $t->dropColumn(['is_final', 'reception_date', 'penalty_weeks', 'penalty_amount']));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn(['goods_procedure', 'goods_origin', 'payment_schedule', 'delay_penalty_rate', 'delay_penalty_cap']));
    }
};
