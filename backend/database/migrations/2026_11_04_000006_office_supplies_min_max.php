<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Fournitures de bureau : montants minimum et maximum d'une commande, simple clause du contrat (décision du 04/11/2026). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            if (!Schema::hasColumn('contracts', 'goods_min_amount')) $t->decimal('goods_min_amount', 15, 2)->nullable();
            if (!Schema::hasColumn('contracts', 'goods_max_amount')) $t->decimal('goods_max_amount', 15, 2)->nullable();
        });
        DB::table('contracts')->where('goods_procedure', 'FOURNITURES_BUREAU')->whereNull('goods_max_amount')->update(['goods_min_amount' => 500, 'goods_max_amount' => 5500]);
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn(['goods_min_amount', 'goods_max_amount']));
    }
};
