<?php

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 1380),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
            'queue' => env('REDIS_QUEUE', 'subtitle-generation'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 1380),
            // Revisit delayed jobs promptly without busy-polling an idle queue.
            'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', 1),
            'after_commit' => false,
        ],

        // Subtitle batch work (analysis, merge, finalization) times out within
        // 300s. A short reservation lets Redis redeliver a killed worker's job
        // before the stalled-job watchdog fails the run.
        'redis-batch' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'queue'),
            'queue' => env('SUBTITLE_BATCH_QUEUE', 'subtitle-batch'),
            'retry_after' => (int) env('REDIS_BATCH_QUEUE_RETRY_AFTER', 360),
            'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', 1),
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],
    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],
];
