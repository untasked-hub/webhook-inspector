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
    $this->get('/privacy')->assertOk()
        ->assertSee('Webhook Inspector Privacy Policy')
        ->assertDontSee('Draft pending legal review')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Info', false)
            ->where('page.slug', 'privacy')
            ->where('page.title', 'Webhook Inspector Privacy Policy')
            ->where('page.eyebrow', 'Legal')
            ->where('page.version', 'Version dated 23 November 2026')
            ->where('page.description', fn ($copy) => str_contains($copy, 'webhook-inspector.untasked.run')
                && str_contains($copy, 'Drag’n Survey SAS')
                && str_contains($copy, 'webhook-inspector@untasked.app')
                && str_contains($copy, '17 rue du Général Leclerc, 94000 Créteil, France')
                && str_contains($copy, '804 864 908'))
            ->has('page.sections', 8)
            ->where('page.sections.0.h', 'Account, billing and support information')
            ->where('page.sections.0.table.headers', ['Information', 'Why we use it', 'Legal basis under the GDPR'])
            ->has('page.sections.0.table.rows', 4)
            ->where('page.sections.0.after.0', fn ($copy) => str_contains($copy, 'Redact credentials and unrelated personal information'))
            ->where('page.sections.1.h', 'Information inside webhooks')
            ->where('page.sections.1.p.2', fn ($copy) => str_contains($copy, 'data processing agreement'))
            ->where('page.sections.2.h', 'Who can receive information')
            ->where('page.sections.2.links.0.href', 'https://stripe.com/privacy')
            ->where('page.sections.3.h', 'International transfers')
            ->where('page.sections.4.h', 'How long we keep information')
            ->has('page.sections.4.list', 4)
            ->where('page.sections.4.list.3.text', fn ($copy) => str_contains($copy, 'French accounting law for ten years'))
            ->where('page.sections.5.h', 'Cookies and browser storage')
            ->where('page.sections.5.links.0.href', 'https://stripe.com/legal/cookies-policy')
            ->where('page.sections.6.h', 'Your privacy rights')
            ->where('page.sections.6.links.0.href', 'https://www.cnil.fr/en/contact-us')
            ->where('page.sections.7.h', 'Changes to this policy'));

    $this->get('/terms')->assertOk()
        ->assertSee('Webhook Inspector Terms of Service')
        ->assertDontSee('Draft pending legal review')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Info', false)
            ->where('page.slug', 'terms')
            ->where('page.title', 'Webhook Inspector Terms of Service')
            ->where('page.version', 'Version dated 23 November 2026')
            ->where('page.description', fn ($copy) => str_contains($copy, 'Drag’n Survey SAS')
                && str_contains($copy, '17 rue du Général Leclerc, 94000 Créteil, France')
                && str_contains($copy, '804 864 908')
                && str_contains($copy, 'webhook-inspector@untasked.app'))
            ->has('page.sections', 8)
            ->where('page.sections.0.h', '1. Who can use the service')
            ->where('page.sections.1.h', '2. Plans and subscriptions')
            ->where('page.sections.1.table.headers', ['Hosted plan', 'Monthly subscription price'])
            ->where('page.sections.1.table.rows', [
                ['Developer', 'USD 9'],
                ['Pro', 'USD 29'],
                ['Team', 'USD 79'],
            ])
            ->where('page.sections.2.h', '3. Cancellation, refunds and failed payments')
            ->where('page.sections.2.p.0', fn ($copy) => str_contains($copy, 'Manage billing'))
            ->where('page.sections.3.h', '4. Webhook traffic and acceptable use')
            ->where('page.sections.4.h', '5. Customer data and confidentiality')
            ->where('page.sections.5.h', '6. Service operation and suspension')
            ->where('page.sections.6.h', '7. Intellectual property and liability')
            ->where('page.sections.7.h', '8. Changes and disputes')
            ->where('page.sections.7.p.1', fn ($copy) => str_contains($copy, 'French law governs these Terms.')));

    $this->get('/security')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Info', false)->where('page.sections.1.h', 'Sensitive reports')
        ->where('page.sections.1.p.0', fn ($copy) => str_contains($copy, 'Do not post vulnerability details')));
    $this->get('/sitemap.xml')->assertOk()->assertSee('/security')->assertSee('/terms');
});
