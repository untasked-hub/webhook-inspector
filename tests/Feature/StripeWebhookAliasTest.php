<?php

it('routes the billing alias to the signed Stripe webhook handler', function (string $path) {
    $response = $this->postJson($path, ['id' => 'evt_test', 'type' => 'ping']);
    expect($response->status())->not->toBe(404)->not->toBe(405)->toBeIn([400, 503]);
})->with(['/stripe/webhook', '/billing/stripe/webhook']);

it('rejects GET on the billing alias', function () {
    expect($this->get('/billing/stripe/webhook')->status())->toBeIn([404, 405]);
});
