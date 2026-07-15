<?php

use Illuminate\Support\Str;

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => (int) env('DB_BUSY_TIMEOUT', 5000),
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'transcribed_subtitle_extension'),
            'username' => env('DB_USERNAME', 'subtitle'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SCHEMA', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            'options' => extension_loaded('pdo_pgsql')
                ? [PDO::ATTR_TIMEOUT => (int) env('DB_CONNECT_TIMEOUT_SECONDS', 5)]
                : [],
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_database_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'timeout' => (float) env('REDIS_CONNECT_TIMEOUT_SECONDS', 5),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT_SECONDS', 5),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'timeout' => (float) env('REDIS_CONNECT_TIMEOUT_SECONDS', 5),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT_SECONDS', 5),
        ],

        'queue' => [
            'url' => env('REDIS_QUEUE_URL', env('REDIS_URL')),
            'host' => env('REDIS_QUEUE_HOST', env('REDIS_HOST', '127.0.0.1')),
            'username' => env('REDIS_QUEUE_USERNAME', env('REDIS_USERNAME')),
            'password' => env('REDIS_QUEUE_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('REDIS_QUEUE_PORT', env('REDIS_PORT', '6379')),
            'database' => env('REDIS_QUEUE_DB', '2'),
            'timeout' => (float) env('REDIS_CONNECT_TIMEOUT_SECONDS', 5),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT_SECONDS', 5),
        ],
    ],

];
