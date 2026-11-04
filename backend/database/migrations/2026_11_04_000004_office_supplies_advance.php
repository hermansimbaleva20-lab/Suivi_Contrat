<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fournitures de bureau : avance sur demande de l'entreprise, sans garantie de restitution (décision du 04/11/2026). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_advances', function (Blueprint $t) {
            $t->string('guarantee_bank', 191)->nullable()->change();
            $t->string('guarantee_number', 120)->nullable()->change();
            $t->decimal('guarantee_amount', 15, 2)->nullable()->change();
            $t->date('guarantee_end_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sans objet : des demandes sans garantie peuvent exister.
    }
};
