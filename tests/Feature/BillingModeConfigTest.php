<?php

use App\Support\Billing;

function loadBillingConfigWithEnv(array $overrides): array
{
    $keys = [
        'UNTASKED_STORAGE_URL',
        'MANAGED_HOSTING',
        'BILLING_ENABLED',
        'BILLING_CHECKOUT_ENABLED',
        'STRIPE_MODE',
        'STRIPE_SECRET',
        'STRIPE_SECRET_KEY',
        'STRIPE_WEBHOOK_SECRET',
        'STRIPE_PRICE_DEVELOPER',
        'STRIPE_PRICE_PRO',
        'STRIPE_PRICE_TEAM',
        'STRIPE_TEST_SECRET_KEY',
        'STRIPE_TEST_WEBHOOK_SECRET',
        'STRIPE_TEST_PRICE_DEVELOPER',
        'STRIPE_TEST_PRICE_PRO',
        'STRIPE_TEST_PRICE_TEAM',
    ];

    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = [
            'getenv' => getenv($key),
            'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            'has_env' => array_key_exists($key, $_ENV),
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'has_server' => array_key_exists($key, $_SERVER),
        ];
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    try {
        foreach ($overrides as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        return require config_path('billing.php');
    } finally {
        foreach ($keys as $key) {
            $prev = $previous[$key];
            if ($prev['getenv'] === false) {
                putenv($key);
            } else {
                putenv("{$key}={$prev['getenv']}");
            }
            if ($prev['has_env']) {
                $_ENV[$key] = $prev['env'];
            } else {
                unset($_ENV[$key]);
            }
            if ($prev['has_server']) {
                $_SERVER[$key] = $prev['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }
    }
}

test('STRIPE_MODE=live with live keys present enables live mode on hosted deployment', function () {
    $cfg = loadBillingConfigWithEnv([
        'UNTASKED_STORAGE_URL' => 'https://storage.internal',
        'STRIPE_MODE' => 'live',
        'STRIPE_SECRET_KEY' => 'sk_live_example123456',
        'STRIPE_WEBHOOK_SECRET' => 'whsec_liveexample123456',
        'STRIPE_PRICE_DEVELOPER' => 'price_live_dev',
        'STRIPE_PRICE_PRO' => 'price_live_pro',
        'STRIPE_PRICE_TEAM' => 'price_live_team',
        'STRIPE_TEST_SECRET_KEY' => 'sk_test_shouldnotbeused',
        'STRIPE_TEST_WEBHOOK_SECRET' => 'whsec_testshouldnotbeused',
        'STRIPE_TEST_PRICE_DEVELOPER' => 'price_test_dev',
        'STRIPE_TEST_PRICE_PRO' => 'price_test_pro',
        'STRIPE_TEST_PRICE_TEAM' => 'price_test_team',
    ]);

    config(['billing' => $cfg]);

    expect($cfg['managed'])->toBeTrue();
    expect($cfg['checkout_enabled'])->toBeTrue();
    expect($cfg['stripe_mode'])->toBe('live');
    expect($cfg['secret'])->toBe('sk_live_example123456');
    expect($cfg['webhook_secret'])->toBe('whsec_liveexample123456');
    expect($cfg['plans']['developer']['stripe_price'])->toBe('price_live_dev');
    expect($cfg['plans']['pro']['stripe_price'])->toBe('price_live_pro');
    expect($cfg['plans']['team']['stripe_price'])->toBe('price_live_team');
    expect(Billing::enabled())->toBeTrue();
});

test('STRIPE_MODE=live with missing live secrets fails closed and never falls back to test keys', function () {
    $cfg = loadBillingConfigWithEnv([
        'UNTASKED_STORAGE_URL' => 'https://storage.internal',
        'STRIPE_MODE' => 'live',
        'STRIPE_TEST_SECRET_KEY' => 'sk_test_example123456',
        'STRIPE_TEST_WEBHOOK_SECRET' => 'whsec_testexample123456',
        'STRIPE_TEST_PRICE_DEVELOPER' => 'price_test_dev',
        'STRIPE_TEST_PRICE_PRO' => 'price_test_pro',
        'STRIPE_TEST_PRICE_TEAM' => 'price_test_team',
    ]);

    config(['billing' => $cfg]);

    expect($cfg['managed'])->toBeTrue();
    expect($cfg['checkout_enabled'])->toBeFalse();
    expect($cfg['secret'])->toBeNull();
    expect($cfg['webhook_secret'])->toBeNull();
    expect($cfg['plans']['developer']['stripe_price'])->toBeNull();
    expect(Billing::enabled())->toBeFalse();
});

test('STRIPE_MODE=live with partial live secrets or test-prefixed key fails closed', function () {
    $partial = loadBillingConfigWithEnv([
        'UNTASKED_STORAGE_URL' => 'https://storage.internal',
        'STRIPE_MODE' => 'live',
        'STRIPE_SECRET_KEY' => 'sk_live_example123456',
        'STRIPE_WEBHOOK_SECRET' => 'whsec_liveexample123456',
        'STRIPE_PRICE_DEVELOPER' => 'price_live_dev',
    ]);
    config(['billing' => $partial]);
    expect($partial['checkout_enabled'])->toBeFalse();
    expect(Billing::enabled())->toBeFalse();

    $wrongPrefix = loadBillingConfigWithEnv([
        'UNTASKED_STORAGE_URL' => 'https://storage.internal',
        'STRIPE_MODE' => 'live',
        'STRIPE_SECRET_KEY' => 'sk_test_mismatched123',
        'STRIPE_WEBHOOK_SECRET' => 'whsec_liveexample123456',
        'STRIPE_PRICE_DEVELOPER' => 'price_live_dev',
        'STRIPE_PRICE_PRO' => 'price_live_pro',
        'STRIPE_PRICE_TEAM' => 'price_live_team',
    ]);
    config(['billing' => $wrongPrefix]);
    expect(Billing::enabled())->toBeFalse();
});

test('omitting STRIPE_MODE preserves hosted test-mode behavior even when live keys exist in env', function () {
    $cfg = loadBillingConfigWithEnv([
        'UNTASKED_STORAGE_URL' => 'https://storage.internal',
        'STRIPE_SECRET_KEY' => 'sk_live_ignored123456',
        'STRIPE_WEBHOOK_SECRET' => 'whsec_liveignored123456',
        'STRIPE_PRICE_DEVELOPER' => 'price_live_dev',
        'STRIPE_PRICE_PRO' => 'price_live_pro',
        'STRIPE_PRICE_TEAM' => 'price_live_team',
        'STRIPE_TEST_SECRET_KEY' => 'sk_test_example123456',
        'STRIPE_TEST_WEBHOOK_SECRET' => 'whsec_testexample123456',
        'STRIPE_TEST_PRICE_DEVELOPER' => 'price_test_dev',
        'STRIPE_TEST_PRICE_PRO' => 'price_test_pro',
        'STRIPE_TEST_PRICE_TEAM' => 'price_test_team',
    ]);

    config(['billing' => $cfg]);

    expect($cfg['managed'])->toBeTrue();
    expect($cfg['checkout_enabled'])->toBeTrue();
    expect($cfg['stripe_mode'])->toBe('test');
    expect($cfg['secret'])->toBe('sk_test_example123456');
    expect($cfg['webhook_secret'])->toBe('whsec_testexample123456');
    expect($cfg['plans']['developer']['stripe_price'])->toBe('price_test_dev');
    expect(Billing::enabled())->toBeTrue();
});
