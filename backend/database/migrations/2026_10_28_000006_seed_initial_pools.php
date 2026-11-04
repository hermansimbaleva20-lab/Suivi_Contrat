<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Décision D1 (28/10/2026) — pools initiaux : PRISE (Antenne MBUJI-MAYI, Antenne KANANGA), PREDIRE (Antenne GBADOLITE).
 * Simples données de départ : l'administration les modifie, désactive ou supprime dans Administration > Organisation.
 * Relançable : un pool déjà présent (même code ou même nom dans le projet) n'est ni recréé ni modifié.
 */
return new class extends Migration
{
    private const POOLS = [
        'PRISE' => [
            ['code' => 'MBUJI-MAYI', 'name' => 'Antenne MBUJI-MAYI', 'province' => 'Kasaï-Oriental'],
            ['code' => 'KANANGA', 'name' => 'Antenne KANANGA', 'province' => 'Kasaï-Central'],
        ],
        'PREDIRE' => [
            ['code' => 'GBADOLITE', 'name' => 'Antenne GBADOLITE', 'province' => 'Nord-Ubangi'],
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('organizational_units')) return;

        foreach (self::POOLS as $projectCode => $pools) {
            $projectId = DB::table('projects')->where('code', $projectCode)->value('id');
            if (!$projectId) continue;

            foreach ($pools as $pool) {
                $exists = DB::table('organizational_units')->where('project_id', $projectId)
                    ->where(fn ($q) => $q->where('code', $pool['code'])->orWhereRaw('LOWER(name) = ?', [mb_strtolower($pool['name'])]))
                    ->exists();
                if ($exists) continue;

                DB::table('organizational_units')->insert($pool + [
                    'project_id' => $projectId, 'type' => 'POOL', 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Volontairement non destructif : des comptes peuvent déjà être rattachés à ces pools.
    }
};
