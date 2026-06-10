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
        'connection' => env('SUBTITLE_QUEUE_CONNECTION', 'redis'),
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
        'worker_groups' => [
            'generation-priority' => [
                'queue_family' => 'generation',
                'tiers' => ['ultimate', 'pro', 'plus', 'base'],
                'worker_count' => (int) env('SUBTITLE_GENERATION_PRIORITY_WORKERS', 4),
            ],
            'batch-priority' => [
                'queue_family' => 'batch',
                'tiers' => ['ultimate', 'pro', 'plus', 'base'],
                'worker_count' => (int) env('SUBTITLE_BATCH_PRIORITY_WORKERS', 20),
            ],
            'base-generation-guarantee' => [
                'queue_family' => 'generation',
                'tiers' => ['base'],
                'worker_count' => (int) env('SUBTITLE_BASE_GENERATION_GUARANTEE_WORKERS', 1),
            ],
            'base-batch-guarantee' => [
                'queue_family' => 'batch',
                'tiers' => ['base'],
                'worker_count' => (int) env('SUBTITLE_BASE_BATCH_GUARANTEE_WORKERS', 2),
            ],
        ],
    ],

    'tiers' => [
        'default' => env('SUBTITLE_DEFAULT_GENERATION_TIER', 'base'),
        'concurrency_cache_store' => env('SUBTITLE_CONCURRENCY_CACHE_STORE', 'subtitle_concurrency'),
        'release_delay_seconds' => (int) env('SUBTITLE_CONCURRENCY_RELEASE_DELAY_SECONDS', 10),
        'lock_seconds' => (int) env('SUBTITLE_CONCURRENCY_LOCK_SECONDS', 10),
        // ~2x the batch job timeout (300s): a slot leaked by a SIGKILLed worker
        // recovers in minutes instead of wedging the user for half an hour.
        'counter_seconds' => (int) env('SUBTITLE_CONCURRENCY_COUNTER_SECONDS', 600),
        'plans' => [
            'ultimate' => [
                'generation_queue' => env('SUBTITLE_GENERATION_QUEUE_ULTIMATE', 'subtitle-generation-ultimate'),
                'batch_queue' => env('SUBTITLE_BATCH_QUEUE_ULTIMATE', 'subtitle-batch-ultimate'),
                'generation_concurrency' => (int) env('SUBTITLE_ULTIMATE_GENERATION_CONCURRENCY', 5),
                'batch_concurrency' => (int) env('SUBTITLE_ULTIMATE_BATCH_CONCURRENCY', 20),
                'budgets_seconds' => [
                    'short' => (int) env('SUBTITLE_ULTIMATE_SHORT_BUDGET_SECONDS', 90),
                    'medium' => (int) env('SUBTITLE_ULTIMATE_MEDIUM_BUDGET_SECONDS', 240),
                    'near_limit' => (int) env('SUBTITLE_ULTIMATE_NEAR_LIMIT_BUDGET_SECONDS', 720),
                ],
            ],
            'base' => [
                'generation_queue' => env('SUBTITLE_GENERATION_QUEUE_BASE', 'subtitle-generation-base'),
                'batch_queue' => env('SUBTITLE_BATCH_QUEUE_BASE', 'subtitle-batch-base'),
                'generation_concurrency' => (int) env('SUBTITLE_BASE_GENERATION_CONCURRENCY', 1),
                'batch_concurrency' => (int) env('SUBTITLE_BASE_BATCH_CONCURRENCY', 3),
                'budgets_seconds' => [
                    'short' => (int) env('SUBTITLE_BASE_SHORT_BUDGET_SECONDS', 240),
                    'medium' => (int) env('SUBTITLE_BASE_MEDIUM_BUDGET_SECONDS', 600),
                    'near_limit' => (int) env('SUBTITLE_BASE_NEAR_LIMIT_BUDGET_SECONDS', 1800),
                ],
            ],
            'plus' => [
                'generation_queue' => env('SUBTITLE_GENERATION_QUEUE_PLUS', 'subtitle-generation-plus'),
                'batch_queue' => env('SUBTITLE_BATCH_QUEUE_PLUS', 'subtitle-batch-plus'),
                'generation_concurrency' => (int) env('SUBTITLE_PLUS_GENERATION_CONCURRENCY', 2),
                'batch_concurrency' => (int) env('SUBTITLE_PLUS_BATCH_CONCURRENCY', 8),
                'budgets_seconds' => [
                    'short' => (int) env('SUBTITLE_PLUS_SHORT_BUDGET_SECONDS', 180),
                    'medium' => (int) env('SUBTITLE_PLUS_MEDIUM_BUDGET_SECONDS', 420),
                    'near_limit' => (int) env('SUBTITLE_PLUS_NEAR_LIMIT_BUDGET_SECONDS', 1320),
                ],
            ],
            'pro' => [
                'generation_queue' => env('SUBTITLE_GENERATION_QUEUE_PRO', 'subtitle-generation-pro'),
                'batch_queue' => env('SUBTITLE_BATCH_QUEUE_PRO', 'subtitle-batch-pro'),
                'generation_concurrency' => (int) env('SUBTITLE_PRO_GENERATION_CONCURRENCY', 3),
                'batch_concurrency' => (int) env('SUBTITLE_PRO_BATCH_CONCURRENCY', 14),
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

    'audio_preparation' => [
        'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffmpeg_timeout_seconds' => (int) env('SUBTITLE_AUDIO_PREP_FFMPEG_TIMEOUT_SECONDS', 600),
        'voice_isolation' => [
            'enabled' => (bool) env('ELEVENLABS_AUDIO_ISOLATION_ENABLED', true),
            'timeout_seconds' => (int) env('ELEVENLABS_AUDIO_ISOLATION_TIMEOUT_SECONDS', 600),
            'fail_open' => (bool) env('ELEVENLABS_AUDIO_ISOLATION_FAIL_OPEN', true),
        ],
    ],

    'transcription' => [
        'timeout_seconds' => (int) env('ELEVENLABS_TRANSCRIPTION_TIMEOUT_SECONDS', 600),
    ],

    'enrichment' => [
        'timeout_seconds' => (int) env('OPENAI_ENRICHMENT_TIMEOUT_SECONDS', 120),
        'cue_batch_size' => (int) env('SUBTITLE_ENRICHMENT_CUE_BATCH_SIZE', 10),
        // Org-level guardrail across all users and workers; per-user tier caps
        // are enforced separately by LimitSubtitleBatchConcurrency. 0 disables.
        'global_rate_limit_per_minute' => (int) env('SUBTITLE_AI_GLOBAL_RATE_LIMIT_PER_MINUTE', 300),
    ],
];
