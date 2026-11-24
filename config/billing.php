<?php

// Explicit STRIPE_MODE=live opts into hosted live secrets (STRIPE_SECRET_KEY + live prices/webhook) fail-closed.
// Legacy generic STRIPE_SECRET is ignored on hosted deployments and never silently falls back to test keys.
$hosted = filled(env('UNTASKED_STORAGE_URL'));
$hasTestVars = collect([
    env('STRIPE_TEST_SECRET_KEY'), env('STRIPE_TEST_WEBHOOK_SECRET'),
    env('STRIPE_TEST_PRICE_DEVELOPER'), env('STRIPE_TEST_PRICE_PRO'), env('STRIPE_TEST_PRICE_TEAM'),
])->contains(fn ($value) => filled($value));

$explicitLive = env('STRIPE_MODE') === 'live';
$liveSecret = env('STRIPE_SECRET_KEY');
$hostedLiveActive = $hosted && $explicitLive && filled($liveSecret);
$hostedLiveMissing = $hosted && $explicitLive && ! filled($liveSecret);

$liveComplete = filled($liveSecret)
    && filled(env('STRIPE_WEBHOOK_SECRET'))
    && filled(env('STRIPE_PRICE_DEVELOPER'))
    && filled(env('STRIPE_PRICE_PRO'))
    && filled(env('STRIPE_PRICE_TEAM'));

$testComplete = filled(env('STRIPE_TEST_SECRET_KEY'))
    && filled(env('STRIPE_TEST_WEBHOOK_SECRET'))
    && filled(env('STRIPE_TEST_PRICE_DEVELOPER'))
    && filled(env('STRIPE_TEST_PRICE_PRO'))
    && filled(env('STRIPE_TEST_PRICE_TEAM'));

// Dedicated hosted test inputs take precedence when not in hosted live mode; partial setup never falls back.
$test = ! $hostedLiveActive && ($hosted || $hasTestVars);

return [
    // Explicit BILLING_ENABLED=false overrides platform detection; standalone defaults to OSS.
    'managed' => env('BILLING_ENABLED') !== null
        ? filter_var(env('BILLING_ENABLED'), FILTER_VALIDATE_BOOL)
        : ($hosted || filter_var(env('MANAGED_HOSTING', false), FILTER_VALIDATE_BOOL)),
    // Checkout/portal/webhook endpoints require explicit opt-in or a complete vault secret set for the active mode.
    // Fails closed if STRIPE_MODE=live on hosted with missing live secrets or if BILLING_CHECKOUT_ENABLED is explicitly false.
    'checkout_enabled' => env('BILLING_CHECKOUT_ENABLED') !== null
        ? filter_var(env('BILLING_CHECKOUT_ENABLED'), FILTER_VALIDATE_BOOL)
        : (
            $hosted && ! $hostedLiveMissing
            && ($hostedLiveActive ? $liveComplete : $testComplete)
        ),
    'stripe_mode' => ($hosted && $explicitLive) ? 'live' : ($test ? 'test' : env('STRIPE_MODE')),
    'secret' => $hostedLiveActive ? $liveSecret : ($hostedLiveMissing ? null : ($test ? env('STRIPE_TEST_SECRET_KEY') : env('STRIPE_SECRET'))),
    'webhook_secret' => $hostedLiveActive ? env('STRIPE_WEBHOOK_SECRET') : ($hostedLiveMissing ? null : ($test ? env('STRIPE_TEST_WEBHOOK_SECRET') : env('STRIPE_WEBHOOK_SECRET'))),
    'plans' => [
        'free' => ['name' => 'Free', 'price' => 0, 'requests' => 1000, 'retention' => 7, 'stripe_price' => null],
        'developer' => ['name' => 'Developer', 'price' => 9, 'requests' => 50000, 'retention' => 30, 'stripe_price' => $hostedLiveActive ? env('STRIPE_PRICE_DEVELOPER') : ($hostedLiveMissing ? null : ($test ? env('STRIPE_TEST_PRICE_DEVELOPER') : env('STRIPE_PRICE_DEVELOPER')))],
        'pro' => ['name' => 'Pro', 'price' => 29, 'requests' => 500000, 'retention' => 60, 'stripe_price' => $hostedLiveActive ? env('STRIPE_PRICE_PRO') : ($hostedLiveMissing ? null : ($test ? env('STRIPE_TEST_PRICE_PRO') : env('STRIPE_PRICE_PRO')))],
        'team' => ['name' => 'Team', 'price' => 79, 'requests' => 2000000, 'retention' => 90, 'stripe_price' => $hostedLiveActive ? env('STRIPE_PRICE_TEAM') : ($hostedLiveMissing ? null : ($test ? env('STRIPE_TEST_PRICE_TEAM') : env('STRIPE_PRICE_TEAM')))],
    ],
];
