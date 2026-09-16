<?php

return [
    'stripe' => [
        // Keep outbound requests and the deployed webhook endpoint on this tested version.
        'api_version' => '2025-03-31.basil',
        'api_base_url' => env('STRIPE_API_BASE_URL', 'https://api.stripe.com/v1'),
        'secret' => env('STRIPE_SECRET'),
        'portal_configuration' => env('STRIPE_BILLING_PORTAL_CONFIGURATION'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance_seconds' => (int) env('STRIPE_WEBHOOK_TOLERANCE_SECONDS', 300),
        'timeout_seconds' => (int) env('STRIPE_TIMEOUT_SECONDS', 15),
        'connect_timeout_seconds' => (int) env('STRIPE_CONNECT_TIMEOUT_SECONDS', 5),
    ],

    'plans' => [
        'base' => [
            'name' => 'Base',
            'price_cents' => 900,
            'stripe_price_id' => env('STRIPE_PRICE_BASE'),
            'monthly_minutes' => 90,
            'generation_tier' => 'base',
            'speed_label' => 'Standard queue',
        ],
        'plus' => [
            'name' => 'Plus',
            'price_cents' => 1900,
            'stripe_price_id' => env('STRIPE_PRICE_PLUS'),
            'monthly_minutes' => 240,
            'generation_tier' => 'plus',
            'speed_label' => 'Priority queue',
        ],
        'pro' => [
            'name' => 'Pro',
            'price_cents' => 3900,
            'stripe_price_id' => env('STRIPE_PRICE_PRO'),
            'monthly_minutes' => 600,
            'generation_tier' => 'pro',
            'speed_label' => 'Fast queue',
        ],
    ],
];
