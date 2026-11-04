<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Mockery n'est pas installé : les commandes artisan (migrations de RefreshDatabase) s'exécutent directement. */
    public $mockConsoleOutput = false;

    /**
     * Garde-fou : les tests qui touchent la base vident leur base (migrate:fresh). Ils ne s'exécutent que sur une base
     * MySQL dédiée dont le nom finit par « _test » (voir phpunit.xml), jamais sur la base de travail.
     */
    protected function beforeRefreshingDatabase()
    {
        $db = (string) config('database.connections.' . config('database.default') . '.database');
        if (!str_ends_with($db, '_test')) {
            throw new \RuntimeException("Tests refusés sur la base « {$db} » : utilisez une base dédiée dont le nom finit par _test.");
        }
    }
}
