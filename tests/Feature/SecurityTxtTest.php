<?php

it('serves an RFC 9116 security.txt with allow-listed branded host and ignores spoofed host headers', function () {
    $res = $this->withHeaders([
        'X-Untasked-Original-Host' => 'webhook-inspector.untasked.run',
        'X-Forwarded-Host' => 'evil.example',
        'X-Forwarded-Proto' => 'https',
    ])->get('/.well-known/security.txt');

    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('text/plain');

    $body = $res->getContent();
    expect($body)
        ->toContain('Contact: https://webhook-inspector.untasked.run/security')
        ->toContain('Expires: ')
        ->toContain('Preferred-Languages: en')
        ->toContain('Canonical: https://webhook-inspector.untasked.run/.well-known/security.txt')
        ->toContain('Policy: https://webhook-inspector.untasked.run/security')
        ->not->toContain('evil.example');

    $spoofed = $this->withHeaders([
        'X-Untasked-Original-Host' => 'evil.example',
        'X-Forwarded-Host' => 'evil.example',
    ])->get('/.well-known/security.txt');

    $spoofed->assertOk();
    expect($spoofed->getContent())->not->toContain('evil.example');
});
