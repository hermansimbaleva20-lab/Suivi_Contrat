<?php

use Pdo\Mysql;

/*
 * Seule la connexion « mysql » est redéfinie (les autres réglages viennent du framework).
 * Le serveur MySQL de WAMP crée les tables en MyISAM par défaut : ce moteur ignore les transactions et les clés
 * étrangères. InnoDB est donc imposé pour toute table créée par les migrations (TDR §4.1 : transactions, clés étrangères).
 */
return [
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'mask_bindings_in_exception_messages' => env('DB_MASK_BINDINGS', false),
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
    ],
];
