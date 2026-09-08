<?php

return [

    'default' => 'openai',
    'default_for_transcription' => 'eleven',

    'providers' => [
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
                'reasoning' => ['effort' => 'high'],
                ...(env('OPENAI_FAST_MODE_ENABLED', true) ? ['service_tier' => 'fast'] : []),
            ],
            'models' => [
                'tokenization' => [
                    'default' => env('OPENAI_TOKENIZATION_MODEL'),
                ],
                // Merged tokenize+translate call; falls back to the
                // tokenization model when no dedicated model is configured.
                'analysis' => [
                    'default' => env('OPENAI_ANALYSIS_MODEL', env('OPENAI_TOKENIZATION_MODEL')),
                ],
                'romanization' => [
                    'default' => env('OPENAI_ROMANIZATION_MODEL'),
                ],
                'enrichment' => [
                    'default' => env('OPENAI_ENRICHMENT_MODEL'),
                ],
            ],
        ],
    ],

];
