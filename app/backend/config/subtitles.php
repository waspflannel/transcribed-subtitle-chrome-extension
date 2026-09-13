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
        'worker_groups' => [
            'generation-priority' => [
                'queue_family' => 'generation',
                'tiers' => ['pro', 'plus', 'base'],
                // Generation work runs as chained stage jobs (acquire ->
                // optimize -> per-chunk transcribe -> merge), so a worker is
                // held only for one stage at a time and tier priority applies
                // at every stage boundary. Each concurrent acquire/optimize/
                // transcribe stage is still a yt-dlp, ffmpeg, or Scribe-upload
                // process, so raise this in step with memory and Scribe rate
                // limits.
                'worker_count' => (int) env('SUBTITLE_GENERATION_PRIORITY_WORKERS', 8),
            ],
            'batch-priority' => [
                'queue_family' => 'batch',
                'tiers' => ['pro', 'plus', 'base'],
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
        // Batch slots turn over every ~2-4s, so a rejected batch that sleeps
        // longer than that just pays a quantized wait for a slot that already
        // freed. Kept short (with ±1s jitter at the release site) so the
        // re-check tracks real slot turnover; the concurrency cap is unchanged,
        // so this adds no 429 risk.
        'release_delay_seconds' => (int) env('SUBTITLE_CONCURRENCY_RELEASE_DELAY_SECONDS', 2),
        'lock_seconds' => (int) env('SUBTITLE_CONCURRENCY_LOCK_SECONDS', 10),
        // ~2x the batch job timeout (300s): a slot leaked by a SIGKILLed worker
        // recovers in minutes instead of wedging the user for half an hour.
        'counter_seconds' => (int) env('SUBTITLE_CONCURRENCY_COUNTER_SECONDS', 600),
        // generation_concurrency caps how many of a user's jobs *process* at
        // once; submission_limit caps how many they can have waiting overall
        // (running + queued). Submissions between the two caps are accepted
        // as queued jobs and promoted FIFO as running slots free up.
        'plans' => [
            'base' => [
                'generation_queue' => env('SUBTITLE_GENERATION_QUEUE_BASE', 'subtitle-generation-base'),
                'batch_queue' => env('SUBTITLE_BATCH_QUEUE_BASE', 'subtitle-batch-base'),
                'generation_concurrency' => (int) env('SUBTITLE_BASE_GENERATION_CONCURRENCY', 1),
                'batch_concurrency' => (int) env('SUBTITLE_BASE_BATCH_CONCURRENCY', 3),
                'submission_limit' => (int) env('SUBTITLE_BASE_SUBMISSION_LIMIT', 3),
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
                'batch_concurrency' => (int) env('SUBTITLE_PLUS_BATCH_CONCURRENCY', 12),
                'submission_limit' => (int) env('SUBTITLE_PLUS_SUBMISSION_LIMIT', 6),
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
                // Kept at/under SUBTITLE_BATCH_PRIORITY_WORKERS (20): a per-user
                // cap above the shared worker count buys nothing. Raise workers
                // in step before pushing this higher.
                'batch_concurrency' => (int) env('SUBTITLE_PRO_BATCH_CONCURRENCY', 20),
                'submission_limit' => (int) env('SUBTITLE_PRO_SUBMISSION_LIMIT', 10),
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
        'cerebras_alignment_microusd_per_call' => (int) env('CEREBRAS_ALIGNMENT_MICROUSD_PER_CALL', 0),
        'cerebras_tokenization_microusd_per_cue' => (int) env('CEREBRAS_TOKENIZATION_MICROUSD_PER_CUE', 0),
        'cerebras_translation_microusd_per_cue' => (int) env('CEREBRAS_TRANSLATION_MICROUSD_PER_CUE', 0),
        'cerebras_romanization_microusd_per_cue' => (int) env('CEREBRAS_ROMANIZATION_MICROUSD_PER_CUE', 0),
        'cerebras_enrichment_microusd_per_cue' => (int) env('CEREBRAS_ENRICHMENT_MICROUSD_PER_CUE', 0),
        'openai_alignment_microusd_per_call' => (int) env('OPENAI_ALIGNMENT_MICROUSD_PER_CALL', 0),
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
        'direct_chunks' => (bool) env('SUBTITLE_AUDIO_DIRECT_CHUNKS', true),
        'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffmpeg_timeout_seconds' => (int) env('SUBTITLE_AUDIO_PREP_FFMPEG_TIMEOUT_SECONDS', 600),
    ],

    // Transcripts are cached per video and shared across users: they derive
    // only from public YouTube audio plus the requested source language, and
    // the cache row carries no user data. The key includes the transcription
    // model id, so model upgrades invalidate old rows. ttl_days <= 0 disables
    // the cache entirely (reads and writes) -- the rollback switch.
    'transcript_cache' => [
        'ttl_days' => (int) env('SUBTITLE_TRANSCRIPT_CACHE_TTL_DAYS', 30),
    ],

    'transcription' => [
        // Compare with upload on representative audio before changing the default.
        'ingestion_mode' => env('SUBTITLE_TRANSCRIPTION_INGESTION_MODE', 'upload'),
        'timeout_seconds' => (int) env('ELEVENLABS_TRANSCRIPTION_TIMEOUT_SECONDS', 600),
        // Long audio is split into overlapping chunks transcribed in
        // parallel, dropping the transcribing ceiling from the full audio
        // length to the longest chunk. Chunks extend overlap_seconds past
        // each boundary on both sides so boundary words are heard whole by a
        // neighbouring chunk; the merger keeps each word once by timestamp
        // midpoint. max_chunks bounds concurrent Scribe uploads -- chunks
        // grow beyond target_seconds for very long videos instead.
        'chunking' => [
            'first_seconds' => (int) env('SUBTITLE_TRANSCRIPTION_CHUNK_FIRST_SECONDS', 15),
            'min_audio_seconds' => (int) env('SUBTITLE_TRANSCRIPTION_CHUNK_MIN_AUDIO_SECONDS', 45),
            'target_seconds' => (int) env('SUBTITLE_TRANSCRIPTION_CHUNK_TARGET_SECONDS', 60),
            'overlap_seconds' => (float) env('SUBTITLE_TRANSCRIPTION_CHUNK_OVERLAP_SECONDS', 2.0),
            'max_chunks' => (int) env('SUBTITLE_TRANSCRIPTION_CHUNK_MAX_CHUNKS', 8),
        ],
    ],

    'enrichment' => [
        'first_batch_seconds' => (int) env('SUBTITLE_ANALYSIS_FIRST_BATCH_SECONDS', 10),
        'first_batch_max_cues' => (int) env('SUBTITLE_ANALYSIS_FIRST_BATCH_MAX_CUES', 2),
        'batch_seconds' => (int) env('SUBTITLE_ANALYSIS_BATCH_SECONDS', 30),
        'balanced_batches' => (bool) env('SUBTITLE_BALANCED_BATCHES', true),
        'timeout_seconds' => (int) env('OPENAI_ENRICHMENT_TIMEOUT_SECONDS', 120),
        // Character-based batch sizing packs cues greedily up to this many
        // cumulative sourceText characters, capped at cue_batch_max_cues.
        // Fewer, size-uniform batches cut per-call overhead and queue
        // contention at identical token cost, and bound content-length
        // outliers. Tune the budget against the invalid-response rate --
        // larger batches mean more output per call.
        'cue_batch_char_budget' => (int) env('SUBTITLE_ENRICHMENT_CUE_BATCH_CHAR_BUDGET', 1000),
        'cue_batch_max_cues' => (int) env('SUBTITLE_ENRICHMENT_CUE_BATCH_MAX_CUES', 20),
        // Org-level guardrail across all users and workers; per-user tier caps
        // are enforced separately by LimitSubtitleBatchConcurrency. 0 disables.
        'global_rate_limit_per_minute' => (int) env('SUBTITLE_AI_GLOBAL_RATE_LIMIT_PER_MINUTE', 300),
    ],

    'stalled_job' => [
        'enabled' => (bool) env('SUBTITLE_STALLED_JOB_WATCHER_ENABLED', true),
        // Buffer added to each stage timeout before a job is considered dead.
        // Comfortably above queue jitter and retry backoff.
        'slack_seconds' => (int) env('SUBTITLE_STALLED_JOB_SLACK_SECONDS', 120),
        // Per-stage ceilings. This watcher is a backstop for dead workers and
        // silently-lost batches, not a competitor to a job's own timeout, so
        // the ceilings deliberately sit above the work each stage performs.
        //   - `preparing` is pre-pickup queue wait; keep it generous so a brief
        //     worker backlog does not fail a job that is merely waiting.
        //   - `acquiring-audio`/`optimizing-audio`/`transcribing` each run as
        //     their own stage job with its own timeout (900/1200/720s), and the
        //     job stage is stamped when the next stage is dispatched, so each
        //     ceiling spans one stage's queue wait plus its work.
        //   - batch stages heartbeat updated_at on
        //     every progress step, so the ceiling only spans one stalled step.
        'stage_timeout_seconds' => [
            'preparing' => (int) env('SUBTITLE_STALLED_PREPARING_TIMEOUT_SECONDS', 900),
            'acquiring-audio' => (int) env('SUBTITLE_STALLED_ACQUIRING_AUDIO_TIMEOUT_SECONDS', 1200),
            'optimizing-audio' => (int) env('SUBTITLE_STALLED_OPTIMIZING_AUDIO_TIMEOUT_SECONDS', 1200),
            'transcribing' => (int) env('SUBTITLE_STALLED_TRANSCRIBING_TIMEOUT_SECONDS', 1200),
            'tokenizing' => (int) env('SUBTITLE_STALLED_TOKENIZING_TIMEOUT_SECONDS', 600),
            'romanizing' => (int) env('SUBTITLE_STALLED_ROMANIZING_TIMEOUT_SECONDS', 600),
            'translating' => (int) env('SUBTITLE_STALLED_TRANSLATING_TIMEOUT_SECONDS', 600),
            'finalizing' => (int) env('SUBTITLE_STALLED_FINALIZING_TIMEOUT_SECONDS', 300),
        ],
        // Fallback for any stage not listed above.
        'default_stage_timeout_seconds' => (int) env('SUBTITLE_STALLED_DEFAULT_TIMEOUT_SECONDS', 600),
    ],
];
