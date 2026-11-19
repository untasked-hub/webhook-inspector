<?php

return [
    // Explicit BILLING_ENABLED=false overrides platform detection; standalone defaults to OSS.
    'managed' => env('BILLING_ENABLED') !== null
        ? filter_var(env('BILLING_ENABLED'), FILTER_VALIDATE_BOOL)
        : (filled(env('UNTASKED_STORAGE_URL')) || filter_var(env('MANAGED_HOSTING', false), FILTER_VALIDATE_BOOL)),
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'plans' => [
        'free' => ['name' => 'Free', 'price' => 0, 'requests' => 1000, 'retention' => 7, 'stripe_price' => null],
        'developer' => ['name' => 'Developer', 'price' => 9, 'requests' => 50000, 'retention' => 30, 'stripe_price' => env('STRIPE_PRICE_DEVELOPER')],
        'pro' => ['name' => 'Pro', 'price' => 29, 'requests' => 500000, 'retention' => 60, 'stripe_price' => env('STRIPE_PRICE_PRO')],
        'team' => ['name' => 'Team', 'price' => 79, 'requests' => 2000000, 'retention' => 90, 'stripe_price' => env('STRIPE_PRICE_TEAM')],
    ],
];
