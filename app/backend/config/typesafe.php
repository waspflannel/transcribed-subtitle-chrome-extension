<?php

return [
    'key' => env('TYPESAFE_API_KEY'),
    'model' => env('TYPESAFE_MODEL', 'jev-latest'),
    'min_confidence' => (float) env('TYPESAFE_ROUTING_MIN_CONFIDENCE', 0.7),
    'routing_microusd_per_call' => (int) env('TYPESAFE_ROUTING_MICROUSD_PER_CALL', 0),

    // Product routing preferences, not measured accuracy guarantees.
    'criteria' => [
        'transcriber' => 'Transcriber prioritizes language understanding. Choose it for non-Latin source text, less common languages, mixed-language passages, dense slang, ambiguous or figurative lyrics, or difficult pronunciation and romanization. Prefer it when the sample is too limited to judge reliably.',
        'spark' => 'Transcriber Spark prioritizes speed. Choose it for straightforward Latin-script text in common languages such as English, Spanish, French, Portuguese, German, Italian, Dutch, or Indonesian, with clear meaning and little slang or ambiguity. Avoid it for non-Latin source text, rare languages, or mixed-language passages. A non-Latin translation target alone does not require Transcriber.',
    ],
];
