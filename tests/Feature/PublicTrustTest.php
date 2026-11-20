<?php

use Inertia\Testing\AssertableInertia as Assert;

it('discloses the quota and subscription fallback without enabling checkout', function () {
    config(['billing.managed' => true, 'billing.stripe_mode' => null]);

    $this->get('/pricing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Info', false)
        ->where('page.billing_enabled', false)
        ->has('page.plans', 4)
        ->where('page.plans.0.key', 'free')
        ->where('page.policies.1.p', fn ($copy) => str_contains($copy, 'no automatic overage charges'))
        ->where('page.policies.2.p', fn ($copy) => str_contains($copy, 'falls back to Free')));
});

it('serves structured legal disclosures and a truthful public security resource', function () {
    foreach (['privacy', 'terms'] as $slug) {
        $this->get('/'.$slug)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Info', false)->where('page.slug', $slug)->has('page.sections', 4));
    }

    $this->get('/security')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Info', false)->where('page.sections.1.h', 'Sensitive reports')
        ->where('page.sections.1.p.0', fn ($copy) => str_contains($copy, 'Do not post vulnerability details')));
    $this->get('/sitemap.xml')->assertOk()->assertSee('/security');
});
