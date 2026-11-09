<?php

return [

    'default' => env('AI_PROVIDER', 'openai'),
    'default_for_transcription' => 'eleven',

    'providers' => [
        // Claude API. Each job carries its own model; fast mode adds `speed: fast` per request.
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
            // structured-outputs makes the SDK send output_config.format instead of a forced tool call.
            'anthropic_beta' => 'structured-outputs-2025-11-13,fast-mode-2026-02-01',
        ],

        'cerebras' => [
            // The installed SDK's Groq transport speaks strict-schema Chat Completions.
            // Register it under Cerebras so credentials, options and metadata stay separate.
            'driver' => 'cerebras',
            'key' => env('CEREBRAS_API_KEY'),
            'url' => env('CEREBRAS_URL', 'https://api.cerebras.ai/v1'),
            'models' => ['text' => ['default' => 'gpt-oss-120b']],
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
            'url' => env('ELEVENLABS_URL'),
            'models' => [
                'transcription' => [
                    'default' => 'scribe_v2',
                ],
            ],
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL'),
            'provider_options' => [
                'reasoning' => ['effort' => env('OPENAI_REASONING_EFFORT', 'low')],
                ...(env('OPENAI_FAST_MODE_ENABLED', true) ? ['service_tier' => 'fast'] : []),
            ],
            'models' => ['text' => ['default' => 'gpt-6-luna']],
        ],
    ],

];
