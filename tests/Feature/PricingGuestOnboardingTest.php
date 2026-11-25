<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function enableHostedSandboxBillingConfig(): void
{
    config([
        'billing.managed' => true,
        'billing.checkout_enabled' => true,
        'billing.stripe_mode' => 'test',
        'billing.secret' => 'sk_test_sandbox_guest_flow_000000000000',
        'billing.webhook_secret' => 'whsec_sandbox_guest_flow_000000000000',
        'billing.plans.developer.stripe_price' => 'price_sandbox_dev_01',
        'billing.plans.pro.stripe_price' => 'price_sandbox_pro_01',
        'billing.plans.team.stripe_price' => 'price_sandbox_team_01',
    ]);
}

test('guest plan selection is preserved through /billing/upgrade and login into /billing/upgrade?plan=<plan> when Sandbox billing is enabled', function () {
    enableHostedSandboxBillingConfig();

    $user = User::create([
        'name' => 'Sandbox Plan User',
        'email' => 'sandbox-login@example.invalid',
        'password' => 'correct-horse-battery',
    ]);

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Info', false)
            ->where('page.billing_enabled', true)
            ->where('page.billing_mode', 'test')
            ->where('page.signed_in', false)
            ->where('page.selected_plan', null)
            ->where('page.billing_note', fn ($copy) => str_contains($copy, 'Stripe Test Mode') && str_contains($copy, 'live paid plans are not yet open'))
        );

    // Clicking a plan CTA links directly to /billing/upgrade?plan=pro; guests are sent to /login?plan=pro.
    $this->get('/billing/upgrade?plan=pro')->assertRedirect('/login?plan=pro');

    $this->get('/login?plan=pro')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth', false)
            ->where('mode', 'login')
            ->where('selected_plan', 'pro')
            ->where('selected_plan_name', 'Pro')
            ->where('billing_enabled', true)
            ->where('billing_mode', 'test')
        );

    // Failed login preserves the chosen plan query string.
    $this->post('/login', [
        'email' => 'sandbox-login@example.invalid',
        'password' => 'wrong-password',
        'plan' => 'pro',
    ])->assertRedirect('/login?plan=pro')
        ->assertSessionHasErrors('email');

    // Successful login redirects directly to /billing/upgrade?plan=pro so the user can accept ToS and start checkout.
    $this->post('/login', [
        'email' => 'sandbox-login@example.invalid',
        'password' => 'correct-horse-battery',
        'plan' => 'pro',
    ])->assertRedirect('/billing/upgrade?plan=pro');

    $this->get('/billing/upgrade?plan=pro&checkout=cancelled')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('BillingUpgrade', false)
            ->where('selected_plan', 'pro')
            ->where('billing_enabled', true)
            ->where('billing_mode', 'test')
            ->where('current_plan', 'free')
            ->where('has_active_paid_sub', false)
            ->where('checkout_cancelled', true)
            ->where('tos_version', \App\Support\Billing::TOS_VERSION)
            ->where('auth.user.name', 'Sandbox Plan User')
            ->has('plans', 3)
        );
});

test('guest plan selection is preserved through registration into /billing/upgrade?plan=<plan> when Sandbox billing is enabled', function () {
    enableHostedSandboxBillingConfig();

    $this->get('/register?plan=team')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth', false)
            ->where('mode', 'register')
            ->where('selected_plan', 'team')
            ->where('selected_plan_name', 'Team')
            ->where('billing_enabled', true)
            ->where('billing_mode', 'test')
        );

    // Validation failure keeps the chosen plan on /register.
    $this->post('/register', [
        'name' => '',
        'email' => 'bad',
        'password' => 'short',
        'password_confirmation' => 'x',
        'plan' => 'team',
    ])->assertRedirect('/register?plan=team')
        ->assertSessionHasErrors(['name', 'email', 'password']);

    $this->post('/register', [
        'name' => 'New Sandbox User',
        'email' => 'sandbox-register@example.invalid',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'plan' => 'team',
    ])->assertRedirect('/billing/upgrade?plan=team');
});

test('untrusted or non-paid plan values are ignored on auth, pricing, and upgrade routes to prevent open redirects', function () {
    enableHostedSandboxBillingConfig();

    foreach (['free', 'enterprise', 'https://evil.example', '//evil.example/pricing', '../dashboard'] as $badPlan) {
        $this->get('/billing/upgrade?plan='.rawurlencode($badPlan))
            ->assertRedirect('/login');

        $this->get('/login?plan='.rawurlencode($badPlan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_plan', null)
                ->where('selected_plan_name', null)
            );

        $this->get('/pricing?plan='.rawurlencode($badPlan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('page.selected_plan', null)
            );
    }

    $this->post('/register', [
        'name' => 'Safe Redirect User',
        'email' => 'safe-redirect@example.invalid',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'plan' => 'https://evil.example',
    ])->assertRedirect('/dashboard');

    // Signed-in user visiting /billing/upgrade with an invalid plan safely falls back to developer.
    $this->get('/billing/upgrade?plan=invalid-plan')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('BillingUpgrade', false)
            ->where('selected_plan', 'developer')
        );
});

test('when billing is disabled (self-host), auth redirects to /dashboard and /billing/upgrade fails closed', function () {
    config([
        'billing.managed' => false,
        'billing.checkout_enabled' => false,
        'billing.stripe_mode' => null,
    ]);

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.billing_enabled', false)
            ->where('page.billing_mode', null)
            ->where('page.billing_note', fn ($copy) => str_contains($copy, 'unavailable'))
        );

    $this->post('/register', [
        'name' => 'Selfhost User',
        'email' => 'selfhost-plan@example.invalid',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        'plan' => 'developer',
    ])->assertRedirect('/dashboard');

    $this->get('/billing/upgrade?plan=developer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('BillingUpgrade', false)
            ->where('billing_enabled', false)
            ->where('billing_mode', null)
        );
});
