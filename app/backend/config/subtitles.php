<?php

return [
    'max_video_duration_seconds' => (int) env('SUBTITLE_MAX_VIDEO_DURATION_SECONDS', 3600),
    'processing_timeout_seconds' => (int) env('SUBTITLE_PROCESSING_TIMEOUT_SECONDS', 0),

    'rate_limits' => [
        'per_install_per_minute' => (int) env('SUBTITLE_RATE_LIMIT_PER_INSTALL_PER_MINUTE', 30),
        'per_ip_per_minute' => (int) env('SUBTITLE_RATE_LIMIT_PER_IP_PER_MINUTE', 120),
        'status_per_install_per_minute' => (int) env('SUBTITLE_STATUS_RATE_LIMIT_PER_INSTALL_PER_MINUTE', 120),
        'status_per_ip_per_minute' => (int) env('SUBTITLE_STATUS_RATE_LIMIT_PER_IP_PER_MINUTE', 300),
    ],

    'queue' => [
        'connection' => env('SUBTITLE_QUEUE_CONNECTION', 'database'),
        'name' => env('SUBTITLE_QUEUE', 'subtitle-ai'),
        'stale_preparing_seconds' => (int) env('SUBTITLE_STALE_PREPARING_SECONDS', 60),
        'worker_timeout_seconds' => (int) env('SUBTITLE_WORKER_TIMEOUT_SECONDS', 1200),
        'auto_start' => [
            'enabled' => (bool) env('SUBTITLE_AUTO_START_WORKERS', env('APP_ENV', 'production') !== 'production'),
            'enabled_in_tests' => (bool) env('SUBTITLE_AUTO_START_WORKERS_IN_TESTS', false),
            'worker_count' => (int) env('SUBTITLE_AUTO_WORKER_COUNT', 0),
            'max_time_seconds' => (int) env('SUBTITLE_AUTO_WORKER_MAX_TIME_SECONDS', 3600),
            'memory_mb' => (int) env('SUBTITLE_AUTO_WORKER_MEMORY_MB', 256),
            'sleep_seconds' => (int) env('SUBTITLE_AUTO_WORKER_SLEEP_SECONDS', 1),
            'tries' => (int) env('SUBTITLE_AUTO_WORKER_TRIES', 0),
            'lock_seconds' => (int) env('SUBTITLE_AUTO_WORKER_LOCK_SECONDS', 10),
        ],
    ],

    'tiers' => [
        'default' => env('SUBTITLE_DEFAULT_GENERATION_TIER', 'base'),
        'release_delay_seconds' => (int) env('SUBTITLE_CONCURRENCY_RELEASE_DELAY_SECONDS', 10),
        'lock_seconds' => (int) env('SUBTITLE_CONCURRENCY_LOCK_SECONDS', 10),
        'counter_seconds' => (int) env('SUBTITLE_CONCURRENCY_COUNTER_SECONDS', 1800),
        'plans' => [
            'ultimate' => [
                'queue' => env('SUBTITLE_QUEUE_ULTIMATE', 'subtitle-ai-ultimate'),
                'per_install_concurrency' => (int) env('SUBTITLE_ULTIMATE_PER_INSTALL_CONCURRENCY', 20),
                'worker_count' => (int) env('SUBTITLE_ULTIMATE_WORKERS', 20),
                'budgets_seconds' => [
                    'short' => (int) env('SUBTITLE_ULTIMATE_SHORT_BUDGET_SECONDS', 90),
                    'medium' => (int) env('SUBTITLE_ULTIMATE_MEDIUM_BUDGET_SECONDS', 240),
                    'near_limit' => (int) env('SUBTITLE_ULTIMATE_NEAR_LIMIT_BUDGET_SECONDS', 720),
                ],
            ],
            'base' => [
                'queue' => env('SUBTITLE_QUEUE_BASE', env('SUBTITLE_QUEUE', 'subtitle-ai')),
                'per_install_concurrency' => (int) env('SUBTITLE_BASE_PER_INSTALL_CONCURRENCY', 1),
                'worker_count' => (int) env('SUBTITLE_BASE_WORKERS', 4),
                'budgets_seconds' => [
                    'short' => (int) env('SUBTITLE_BASE_SHORT_BUDGET_SECONDS', 240),
                    'medium' => (int) env('SUBTITLE_BASE_MEDIUM_BUDGET_SECONDS', 600),
                    'near_limit' => (int) env('SUBTITLE_BASE_NEAR_LIMIT_BUDGET_SECONDS', 1800),
                ],
            ],
            'plus' => [
                'queue' => env('SUBTITLE_QUEUE_PLUS', 'subtitle-ai-plus'),
                'per_install_concurrency' => (int) env('SUBTITLE_PLUS_PER_INSTALL_CONCURRENCY', 2),
                'worker_count' => (int) env('SUBTITLE_PLUS_WORKERS', 2),
                'budgets_seconds' => [
                    'short' => (int) env('SUBTITLE_PLUS_SHORT_BUDGET_SECONDS', 180),
                    'medium' => (int) env('SUBTITLE_PLUS_MEDIUM_BUDGET_SECONDS', 420),
                    'near_limit' => (int) env('SUBTITLE_PLUS_NEAR_LIMIT_BUDGET_SECONDS', 1320),
                ],
            ],
            'pro' => [
                'queue' => env('SUBTITLE_QUEUE_PRO', 'subtitle-ai-pro'),
                'per_install_concurrency' => (int) env('SUBTITLE_PRO_PER_INSTALL_CONCURRENCY', 3),
                'worker_count' => (int) env('SUBTITLE_PRO_WORKERS', 2),
                'budgets_seconds' => [
                    'short' => (int) env('SUBTITLE_PRO_SHORT_BUDGET_SECONDS', 120),
                    'medium' => (int) env('SUBTITLE_PRO_MEDIUM_BUDGET_SECONDS', 300),
                    'near_limit' => (int) env('SUBTITLE_PRO_NEAR_LIMIT_BUDGET_SECONDS', 900),
                ],
            ],
        ],
    ],

    'tracing' => [
        'slow_queue_wait_ms' => (int) env('SUBTITLE_TRACE_SLOW_QUEUE_WAIT_MS', 30000),
        'slow_stage_ms' => (int) env('SUBTITLE_TRACE_SLOW_STAGE_MS', 120000),
    ],

    'costs' => [
        'elevenlabs_scribe_microusd_per_minute' => (int) env('ELEVENLABS_SCRIBE_MICROUSD_PER_MINUTE', 0),
        'openai_tokenization_microusd_per_cue' => (int) env('OPENAI_TOKENIZATION_MICROUSD_PER_CUE', 0),
        'openai_translation_microusd_per_cue' => (int) env('OPENAI_TRANSLATION_MICROUSD_PER_CUE', 0),
        'openai_romanization_microusd_per_cue' => (int) env('OPENAI_ROMANIZATION_MICROUSD_PER_CUE', 0),
        'openai_enrichment_microusd_per_cue' => (int) env('OPENAI_ENRICHMENT_MICROUSD_PER_CUE', 0),
    ],

    'youtube' => [
        'binary' => env('YOUTUBE_AUDIO_BINARY', 'yt-dlp'),
        'metadata_timeout_seconds' => (int) env('YOUTUBE_METADATA_TIMEOUT_SECONDS', 60),
        'download_timeout_seconds' => (int) env('YOUTUBE_AUDIO_DOWNLOAD_TIMEOUT_SECONDS', 600),
        'temp_directory' => storage_path('app/private/audio-processing'),
    ],

    'transcription' => [
        'timeout_seconds' => (int) env('ELEVENLABS_TRANSCRIPTION_TIMEOUT_SECONDS', 600),
    ],

    'enrichment' => [
        'timeout_seconds' => (int) env('OPENAI_ENRICHMENT_TIMEOUT_SECONDS', 120),
        'cue_batch_size' => (int) env('SUBTITLE_ENRICHMENT_CUE_BATCH_SIZE', 10),
    ],
];
