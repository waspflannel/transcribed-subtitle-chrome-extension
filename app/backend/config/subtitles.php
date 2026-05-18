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
        'auto_start_workers' => filter_var(env('SUBTITLE_AUTO_START_WORKERS', env('APP_ENV') === 'local'), FILTER_VALIDATE_BOOL),
        'auto_worker_count' => (int) env('SUBTITLE_AUTO_WORKER_COUNT', 3),
        'auto_worker_max_time_seconds' => (int) env('SUBTITLE_AUTO_WORKER_MAX_TIME_SECONDS', 900),
        'auto_worker_sleep_seconds' => (int) env('SUBTITLE_AUTO_WORKER_SLEEP_SECONDS', 1),
        'auto_worker_timeout_seconds' => (int) env('SUBTITLE_AUTO_WORKER_TIMEOUT_SECONDS', 1200),
        'stale_preparing_seconds' => (int) env('SUBTITLE_STALE_PREPARING_SECONDS', 60),
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
