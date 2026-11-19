<?php

use App\Support\SafeHttp;

test('dual-stack targets select the public IPv4 for pinned delivery', function () {
    $seen = null;
    SafeHttp::fakeTransport(function ($method, $target) use (&$seen) {
        $seen = $target;
        return ['status' => 204];
    });
    try {
        $result = SafeHttp::send('POST', 'https://example.com/webhook', [], '{}',
            fn () => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']);
        expect($result['ok'])->toBeTrue()->and($seen['ip'])->toBe('93.184.216.34');
    } finally {
        SafeHttp::fakeTransport(null);
    }
});

test('a private IPv4 answer cannot hide behind a public answer', function () {
    SafeHttp::validateTarget('https://example.com', fn () => ['93.184.216.34', '127.0.0.1']);
})->throws(RuntimeException::class, 'private, reserved, or disallowed');

test('IPv6-only resolver results fail closed without an IPv4 destination', function () {
    SafeHttp::validateTarget('https://example.com', fn () => ['2606:2800:220:1:248:1893:25c8:1946']);
})->throws(RuntimeException::class, 'requires a public IPv4');
