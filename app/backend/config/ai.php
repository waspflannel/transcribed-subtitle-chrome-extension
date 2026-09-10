<?php

return [

    'default' => env('AI_PROVIDER', 'openai'),
    'default_for_transcription' => 'eleven',

    'providers' => [
        'cerebras' => [
            // The installed SDK's Groq transport speaks strict-schema Chat Completions.
            // Register it under Cerebras so credentials, options and metadata stay separate.
            'driver' => 'cerebras',
            'key' => env('CEREBRAS_API_KEY'),
            'url' => env('CEREBRAS_URL', 'https://api.cerebras.ai/v1'),
            'models' => [
                'tokenization' => ['default' => env('CEREBRAS_TOKENIZATION_MODEL', env('CEREBRAS_MODEL', 'gpt-oss-120b'))],
                'analysis' => ['default' => env('CEREBRAS_ANALYSIS_MODEL', env('CEREBRAS_MODEL', 'gpt-oss-120b'))],
                'romanization' => ['default' => env('CEREBRAS_ROMANIZATION_MODEL', env('CEREBRAS_MODEL', 'gpt-oss-120b'))],
                'enrichment' => ['default' => env('CEREBRAS_ENRICHMENT_MODEL', env('CEREBRAS_MODEL', 'gpt-oss-120b'))],
            ],
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
            'url' => env('ELEVENLABS_URL'),
            'models' => [
                'transcription' => [
                    'default' => env('ELEVENLABS_TRANSCRIPTION_MODEL'),
                ],
            ],
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL'),
            'provider_options' => [
                'reasoning' => ['effort' => 'low'],
                ...(env('OPENAI_FAST_MODE_ENABLED', true) ? ['service_tier' => 'fast'] : []),
            ],
            'models' => [
                'tokenization' => [
                    'default' => env('OPENAI_TOKENIZATION_MODEL', env('OPENAI_MODEL', 'gpt-5.6-luna')),
                ],
                // Merged tokenize+translate call; falls back to the
                // tokenization model when no dedicated model is configured.
                'analysis' => [
                    'default' => env('OPENAI_ANALYSIS_MODEL', env('OPENAI_TOKENIZATION_MODEL', env('OPENAI_MODEL', 'gpt-5.6-luna'))),
                ],
                'romanization' => [
                    'default' => env('OPENAI_ROMANIZATION_MODEL', env('OPENAI_MODEL', 'gpt-5.6-luna')),
                ],
                'enrichment' => [
                    'default' => env('OPENAI_ENRICHMENT_MODEL', env('OPENAI_MODEL', 'gpt-5.6-luna')),
                ],
            ],
        ],
    ],

];
