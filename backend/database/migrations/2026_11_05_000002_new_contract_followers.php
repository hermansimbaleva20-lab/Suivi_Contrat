<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comptes notifiés de chaque nouveau contrat pour en commencer le suivi (décision du 05/11/2026) : le chef de cellule
 * infrastructures et le personnel de sa cellule, choisis par le super-administrateur dans Utilisateurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'can_follow_new_contracts')) $t->boolean('can_follow_new_contracts')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('can_follow_new_contracts'));
    }
};
