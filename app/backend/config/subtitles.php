<?php

return [
    'max_video_duration_seconds' => (int) env('SUBTITLE_MAX_VIDEO_DURATION_SECONDS', 3600),

    'youtube' => [
        'binary' => env('YOUTUBE_AUDIO_BINARY', 'yt-dlp'),
        'metadata_timeout_seconds' => (int) env('YOUTUBE_METADATA_TIMEOUT_SECONDS', 60),
        'download_timeout_seconds' => (int) env('YOUTUBE_AUDIO_DOWNLOAD_TIMEOUT_SECONDS', 600),
        'temp_directory' => storage_path('app/private/audio-processing'),
    ],

    'transcription' => [
        'provider' => env('TRANSCRIPTION_PROVIDER', 'openai_verbose'),
        'model' => env('OPENAI_TRANSCRIPTION_MODEL', 'whisper-1'),
        'timeout_seconds' => (int) env('OPENAI_TRANSCRIPTION_TIMEOUT_SECONDS', 600),
        'connect_timeout_seconds' => (int) env('OPENAI_TRANSCRIPTION_CONNECT_TIMEOUT_SECONDS', 10),
    ],
];
