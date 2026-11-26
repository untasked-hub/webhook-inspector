<?php

use App\Support\Billing;
use Inertia\Testing\AssertableInertia as Assert;

it('provides stable resource slugs and preserved sections', function (string $slug) {
    $this->get('/'.$slug)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Info', false)->where('page.slug', $slug)->has('page.title')->has('page.description')->has('page.sections'));
})->with(['docs', 'self-hosting', 'privacy', 'terms']);

it('provides authoritative plan keys and billing unavailable state', function () {
    config(['billing.managed' => false]);
    $this->get('/pricing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Info', false)->where('page.slug', 'pricing')->where('page.billing_enabled', false)
        ->where('page.billing_note', 'Paid checkout is currently unavailable on this deployment: billing is not configured.')
        ->has('page.plans', 4)->where('page.plans.0.key', 'free')
        ->where('page.plans.2.key', 'pro')->where('page.plans.2.price', config('billing.plans.pro.price'))
        ->where('page.plans.2.requests', config('billing.plans.pro.requests')));
});

it('omits the obsolete pricing notice when live checkout is enabled', function () {
    config([
        'billing.managed' => true,
        'billing.checkout_enabled' => true,
        'billing.stripe_mode' => 'live',
        'billing.secret' => 'sk_live_fixture',
        'billing.webhook_secret' => 'whsec_fixture',
        'billing.plans.developer.stripe_price' => 'price_live_fixture_developer',
        'billing.plans.pro.stripe_price' => 'price_live_fixture_pro',
        'billing.plans.team.stripe_price' => 'price_live_fixture_team',
    ]);

    expect(Billing::enabled())->toBeTrue();

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.billing_enabled', true)
            ->where('page.billing_mode', 'live'))
        ->assertDontSee('Checkout is configured on this deployment. Manage checkout from your authenticated account. Configuration does not verify payment processing.');
});
