<?php

return [

    'default' => env('CACHE_STORE', 'database'),

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', env('REDIS_CACHE_CONNECTION', 'cache')),
        ],

        'subtitle_concurrency' => [
            'driver' => 'redis',
            'connection' => env('SUBTITLE_CONCURRENCY_REDIS_CONNECTION', env('REDIS_CACHE_CONNECTION', 'cache')),
            'lock_connection' => env(
                'SUBTITLE_CONCURRENCY_REDIS_LOCK_CONNECTION',
                env('SUBTITLE_CONCURRENCY_REDIS_CONNECTION', env('REDIS_CACHE_CONNECTION', 'cache')),
            ),
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'transcribed-subtitle-extension-cache-'),

    'serializable_classes' => false,

];
