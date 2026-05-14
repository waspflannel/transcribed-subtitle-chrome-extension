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
            'models' => [
                'tokenization' => [
                    'default' => env('OPENAI_TOKENIZATION_MODEL'),
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
