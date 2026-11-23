<?php

// Dedicated hosted test inputs take precedence as a group; partial setup never falls back.
$test = filled(env('UNTASKED_STORAGE_URL')) || collect([
    env('STRIPE_TEST_SECRET_KEY'), env('STRIPE_TEST_WEBHOOK_SECRET'),
    env('STRIPE_TEST_PRICE_DEVELOPER'), env('STRIPE_TEST_PRICE_PRO'), env('STRIPE_TEST_PRICE_TEAM'),
])->contains(fn ($value) => filled($value));

return [
    // Explicit BILLING_ENABLED=false overrides platform detection; standalone defaults to OSS.
    'managed' => env('BILLING_ENABLED') !== null
        ? filter_var(env('BILLING_ENABLED'), FILTER_VALIDATE_BOOL)
        : (filled(env('UNTASKED_STORAGE_URL')) || filter_var(env('MANAGED_HOSTING', false), FILTER_VALIDATE_BOOL)),
    // Checkout/portal/webhook endpoints require explicit opt-in or dedicated test-mode vault secrets on managed hosting.
    // Fails closed if any live key/mode is present or if BILLING_CHECKOUT_ENABLED is explicitly false.
    'checkout_enabled' => env('BILLING_CHECKOUT_ENABLED') !== null
        ? filter_var(env('BILLING_CHECKOUT_ENABLED'), FILTER_VALIDATE_BOOL)
        : (
            filled(env('UNTASKED_STORAGE_URL'))
            && filled(env('STRIPE_TEST_SECRET_KEY'))
            && filled(env('STRIPE_TEST_WEBHOOK_SECRET'))
            && filled(env('STRIPE_TEST_PRICE_DEVELOPER'))
            && filled(env('STRIPE_TEST_PRICE_PRO'))
            && filled(env('STRIPE_TEST_PRICE_TEAM'))
        ),
    'stripe_mode' => $test ? 'test' : env('STRIPE_MODE'),
    'secret' => $test ? env('STRIPE_TEST_SECRET_KEY') : env('STRIPE_SECRET'),
    'webhook_secret' => $test ? env('STRIPE_TEST_WEBHOOK_SECRET') : env('STRIPE_WEBHOOK_SECRET'),
    'plans' => [
        'free' => ['name' => 'Free', 'price' => 0, 'requests' => 1000, 'retention' => 7, 'stripe_price' => null],
        'developer' => ['name' => 'Developer', 'price' => 9, 'requests' => 50000, 'retention' => 30, 'stripe_price' => $test ? env('STRIPE_TEST_PRICE_DEVELOPER') : env('STRIPE_PRICE_DEVELOPER')],
        'pro' => ['name' => 'Pro', 'price' => 29, 'requests' => 500000, 'retention' => 60, 'stripe_price' => $test ? env('STRIPE_TEST_PRICE_PRO') : env('STRIPE_PRICE_PRO')],
        'team' => ['name' => 'Team', 'price' => 79, 'requests' => 2000000, 'retention' => 90, 'stripe_price' => $test ? env('STRIPE_TEST_PRICE_TEAM') : env('STRIPE_PRICE_TEAM')],
    ],
];
