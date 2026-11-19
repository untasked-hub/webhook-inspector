<?php

use Inertia\Testing\AssertableInertia as Assert;

it('provides stable resource slugs and preserved sections', function (string $slug) {
    $this->get('/'.$slug)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Info', false)->where('page.slug', $slug)->has('page.title')->has('page.description')->has('page.sections'));
})->with(['docs', 'self-hosting', 'privacy', 'terms']);

it('provides authoritative plan keys and billing unavailable state', function () {
    config(['billing.managed' => false]);
    $this->get('/pricing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Info', false)->where('page.slug', 'pricing')->where('page.billing_enabled', false)
        ->has('page.plans', 4)->where('page.plans.0.key', 'free')
        ->where('page.plans.2.key', 'pro')->where('page.plans.2.price', config('billing.plans.pro.price'))
        ->where('page.plans.2.requests', config('billing.plans.pro.requests')));
});

it('reports configured billing without changing plan values', function () {
    config(['billing.managed' => true, 'billing.secret' => 'synthetic', 'billing.webhook_secret' => 'synthetic']);
    foreach (['developer', 'pro', 'team'] as $key) config(['billing.plans.'.$key.'.stripe_price' => 'synthetic']);
    $this->get('/pricing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('page.billing_enabled', true)->where('page.billing_note', 'Checkout is available.'));
});
