<?php

use App\Support\ForwardingFilter;

it('requires every configured forwarding condition to match', function () {
    $filter = ['method' => 'POST', 'header' => 'X-Provider', 'header_value' => 'demo', 'json_path' => 'event.type', 'json_value' => 'created'];
    $headers = ['x-provider' => ['other', 'demo']];
    $body = '{"event":{"type":"created"}}';

    expect(ForwardingFilter::matches($filter, 'POST', $headers, $body))->toBeTrue()
        ->and(ForwardingFilter::matches($filter, 'GET', $headers, $body))->toBeFalse()
        ->and(ForwardingFilter::matches($filter, 'POST', [], $body))->toBeFalse()
        ->and(ForwardingFilter::matches($filter, 'POST', $headers, '{'))->toBeFalse()
        ->and(ForwardingFilter::matches($filter, 'POST', $headers, '{"event":{"type":1}}'))->toBeFalse();
});

it('rejects sensitive header conditions and preserves unfiltered forwarding', function () {
    expect(ForwardingFilter::matches(['header' => 'Authorization', 'header_value' => 'demo'], 'POST', ['authorization' => ['demo']], ''))->toBeFalse()
        ->and(ForwardingFilter::matches([], 'POST', [], ''))->toBeTrue();
});
