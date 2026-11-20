<?php

use App\Models\User;
use App\Jobs\DeliverWebhook;
use App\Support\Billing;
use App\Support\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('hosted billing configuration never falls back to generic live credentials', function () {
    $env = \Illuminate\Support\Env::getRepository();
    $values = ['UNTASKED_STORAGE_URL' => 'https://storage.example.invalid', 'STRIPE_MODE' => 'live', 'STRIPE_SECRET' => 'sk_live_fixture', 'STRIPE_PRICE_PRO' => 'price_generic'];
    foreach ($values as $name => $value) $env->set($name, $value);
    try {
        config(['billing' => require config_path('billing.php')]);
        expect(config('billing.stripe_mode'))->toBe('test');
        expect(config('billing.secret'))->toBeNull();
        expect(config('billing.plans.pro.stripe_price'))->toBeNull();
        expect(Billing::enabled())->toBeFalse();
    } finally {
        foreach ($values as $name => $value) $env->clear($name);
    }
});

test('billing remains disabled when Stripe secrets are present unless BILLING_CHECKOUT_ENABLED is explicitly true', function () {
    $env = \Illuminate\Support\Env::getRepository();
    $values = [
        'UNTASKED_STORAGE_URL' => 'https://storage.example.invalid',
        'STRIPE_TEST_SECRET_KEY' => 'sk_test_fixture',
        'STRIPE_TEST_WEBHOOK_SECRET' => 'whsec_fixture',
        'STRIPE_TEST_PRICE_DEVELOPER' => 'price_dev',
        'STRIPE_TEST_PRICE_PRO' => 'price_pro',
        'STRIPE_TEST_PRICE_TEAM' => 'price_team',
    ];
    foreach ($values as $name => $value) $env->set($name, $value);
    $env->clear('BILLING_CHECKOUT_ENABLED');
    try {
        config(['billing' => require config_path('billing.php')]);
        expect(config('billing.managed'))->toBeTrue();
        expect(config('billing.checkout_enabled'))->toBeFalse();
        expect(Billing::enabled())->toBeFalse();
        expect(Billing::configurationChecks()['billing checkout enabled'])->toBeFalse();

        $this->actingAs(newUser());
        $this->postJson('/api/billing/checkout', ['plan' => 'developer'])->assertStatus(503);
        $this->postJson('/api/billing/portal')->assertStatus(503);
        $this->postJson('/stripe/webhook')->assertStatus(503);
        $this->getJson('/api/usage')->assertOk()->assertJsonPath('billing_enabled', false)->assertJsonPath('billing_mode', null);
        $this->get('/pricing')->assertOk()->assertInertia(fn ($page) => $page
            ->where('page.billing_enabled', false)
            ->where('page.billing_note', 'Paid checkout is currently unavailable on this deployment: billing is not configured.'));

        $env->set('BILLING_CHECKOUT_ENABLED', 'true');
        config(['billing' => require config_path('billing.php')]);
        expect(config('billing.checkout_enabled'))->toBeTrue();
        expect(Billing::enabled())->toBeTrue();
    } finally {
        foreach ($values as $name => $value) $env->clear($name);
        $env->clear('BILLING_CHECKOUT_ENABLED');
    }
});

function billingConfig(): void {
    config(['billing.managed' => true, 'billing.checkout_enabled' => true, 'billing.stripe_mode' => 'test', 'billing.secret' => 'sk_test_fixture', 'billing.webhook_secret' => 'whsec_fixture', 'billing.plans.developer.stripe_price' => 'price_dev', 'billing.plans.pro.stripe_price' => 'price_pro', 'billing.plans.team.stripe_price' => 'price_team']);
}
function newUser(): User {
    return User::create(['name' => 'Synthetic', 'email' => uniqid('u_').'@example.invalid', 'password' => bcrypt('Secret123456!')]);
}
function sampleEndpoint($test): array {
    $test->actingAs(newUser());
    $p = $test->postJson('/api/projects', ['name' => 'Synthetic'])->assertCreated()->json('id');
    $e = $test->postJson('/api/endpoints', ['name' => 'Synthetic', 'project_id' => $p])->assertCreated()->json('id');
    return [$e, $test->getJson('/api/projects')->json('endpoints.0.token')];
}
function signedEvent($test, array $event, int $offset = 0) {
    $body = json_encode($event + ['livemode' => false]); $t = time() + $offset;
    $sig = hash_hmac('sha256', $t.'.'.$body, 'whsec_fixture');
    return $test->call('POST', '/stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$t.',v1='.$sig], $body);
}

test('public information pages render and disclose billing state', function () {
    config(['billing.managed' => true, 'billing.secret' => null, 'inertia.pages.paths' => [resource_path('js/Pages')]]);
    foreach (['pricing', 'docs', 'self-hosting', 'privacy', 'terms'] as $slug) {
        $this->get('/'.$slug)->assertOk()->assertInertia(fn ($page) => $page->component('Info')->has('page.title')->has('page.sections'));
    }
    $this->get('/pricing')->assertInertia(fn ($page) => $page->where('page.billing_note', 'Paid checkout is currently unavailable on this deployment: billing is not configured.')->has('page.plans', 4));
    $this->get('/missing-page')->assertNotFound();
    billingConfig();
    $this->get('/pricing')->assertInertia(fn ($page) => $page->where('page.billing_enabled', true));
});

test('combined history filters and bounded exports honor tenants and redact nested secrets', function () {
    Queue::fake();
    [$e, $token] = sampleEndpoint($this);
    $this->postJson('/c/'.$token.'?ok=yes&nested[secret]=hidden&nested[ok]=2', ['sample' => true])->assertStatus(202);
    $this->get('/c/'.$token.'?other=1')->assertStatus(202);
    $filters = '?method=POST&content_type=application%2Fjson&min_size=1&max_size=100&from='.urlencode(now()->subDay()->toIso8601String()).'&to='.urlencode(now()->addDay()->toIso8601String());
    $list = $this->getJson('/api/endpoints/'.$e.'/requests'.$filters)->assertOk()->json('requests');
    expect($list)->toHaveCount(1);
    $this->getJson('/api/endpoints/'.$e.'/requests?max_size=0')->assertOk()->assertJsonCount(1, 'requests');
    $this->getJson('/api/endpoints/'.$e.'/requests?method=INVALID')->assertUnprocessable();
    $this->getJson('/api/endpoints/'.$e.'/requests?before='.$list[0]['id'])->assertOk()->assertJsonCount(0, 'requests');
    foreach (['json', 'ndjson', 'curl'] as $format) {
        $res = $this->get('/api/endpoints/'.$e.'/requests'.$filters.'&format='.$format)->assertOk()->assertHeader('X-Export-Limit', '100');
        expect($res->getContent())->not->toContain($token)->not->toContain('hidden');
        if ($format === 'json') expect(json_decode($res->getContent(), true))->toHaveCount(1);
        if ($format === 'curl') expect($res->getContent())->toContain('example.invalid')->toContain('ok=yes');
    }
    $this->postJson('/api/endpoints/'.$e.'/requests/'.$list[0]['id'].'/replay', ['url' => 'https://93.184.216.34/event'])->assertUnprocessable();
    $this->postJson('/api/endpoints/'.$e.'/requests/'.$list[0]['id'].'/replay', ['url' => 'https://93.184.216.34/event?secret=hidden', 'confirm' => true])->assertUnprocessable();
    $r = $this->postJson('/api/endpoints/'.$e.'/requests/'.$list[0]['id'].'/replay', ['url' => 'https://93.184.216.34/private-path', 'confirm' => true])->assertStatus(202)->json('id');
    expect(DB::table('deliveries')->where('id', $r)->value('target_url'))->not->toContain('private-path');
    $this->getJson('/api/endpoints/'.$e.'/deliveries')->assertOk()->assertDontSee('private-path');
    SafeHttp::fakeTransport(function ($method, $target, $headers, $body) {
        expect($target['url'])->toContain('ok=yes')->toContain('nested%5Bok%5D=2')->not->toContain('secret')->not->toContain('hidden');
        expect($method)->toBe('POST');
        return ['status' => 200];
    });
    try { (new DeliverWebhook($r))->handle(); } finally { SafeHttp::fakeTransport(null); }
    expect(DB::table('deliveries')->where('id', $r)->value('status'))->toBe('succeeded');
    $this->actingAs(newUser());
    foreach (['requests?format=json', 'deliveries', 'forward'] as $path) $this->getJson('/api/endpoints/'.$e.'/'.$path)->assertNotFound();
    $this->postJson('/api/endpoints/'.$e.'/requests/'.$list[0]['id'].'/replay', ['url' => 'https://93.184.216.34', 'confirm' => true])->assertNotFound();
});

test('monthly quota counts across endpoints, persists after deletion, rolls over and limits retention', function () {
    config(['billing.managed' => true, 'billing.plans.free.requests' => 2]);
    [$e, $token] = sampleEndpoint($this);
    $user = auth()->id();
    $this->postJson('/c/'.$token, ['n' => 1])->assertStatus(202);
    $this->postJson('/c/'.$token, ['n' => 2])->assertStatus(202);
    $this->deleteJson('/api/endpoints/'.$e.'/requests')->assertNoContent();
    $this->postJson('/c/'.$token, ['n' => 3])->assertStatus(429);
    $p = DB::table('endpoints')->where('id', $e)->value('project_id');
    $e2 = $this->postJson('/api/endpoints', ['project_id' => $p, 'name' => 'Other'])->assertCreated()->json('id');
    $token2 = DB::table('endpoints')->where('id', $e2)->value('token');
    $this->postJson('/c/'.$token2)->assertStatus(429);
    $this->getJson('/api/usage')->assertOk()->assertJsonPath('requests', 2)->assertJsonPath('limits.requests', 2);
    $this->putJson('/api/endpoints/'.$e.'/retention', ['retention_days' => 8])->assertUnprocessable();
    DB::table('billing_accounts')->insert(['user_id' => $user, 'plan' => 'developer', 'status' => 'active', 'paid_until' => now()->addMonth(), 'created_at' => now(), 'updated_at' => now()]);
    expect(Billing::plan($user))->toBe('developer');
    $this->postJson('/c/'.$token)->assertStatus(202);
    $this->putJson('/api/endpoints/'.$e.'/retention', ['retention_days' => 14])->assertNoContent();
    $this->travelTo(now()->startOfMonth()->addMonth());
    $this->postJson('/c/'.$token)->assertStatus(202);
    $this->getJson('/api/usage')->assertJsonPath('requests', 1);
    $this->travelBack();
    DB::table('billing_accounts')->where('user_id', $user)->update(['paid_until' => now()->subDay()]);
    expect(Billing::plan($user))->toBe('free');
    config(['billing.managed' => false]);
    expect(Billing::limits($user)['retention'])->toBe(365);
});

test('billing disabled state and authentication fail closed', function (string $key, mixed $value) {
    billingConfig();
    config([$key => $value]);
    Http::fake();
    $this->postJson('/api/billing/checkout', ['plan' => 'developer'])->assertUnauthorized();
    $this->actingAs(newUser());
    $this->postJson('/api/billing/checkout', ['plan' => 'developer'])->assertStatus(503);
    $this->postJson('/api/billing/portal')->assertStatus(503);
    $this->postJson('/stripe/webhook')->assertStatus(503);
    $this->getJson('/api/usage')->assertJsonPath('billing_enabled', false);
    Http::assertNothingSent();
})->with([
    'self-hosted mode' => ['billing.managed', false],
    'checkout gate disabled' => ['billing.checkout_enabled', false],
    'missing mode' => ['billing.stripe_mode', null],
    'missing API key' => ['billing.secret', null],
    'live API key in test preview' => ['billing.secret', 'sk_live_fixture'],
    'test API key in live mode' => ['billing.stripe_mode', 'live'],
    'duplicate price' => ['billing.plans.pro.stripe_price', 'price_dev'],
    'missing signing secret' => ['billing.webhook_secret', null],
    'missing Developer price' => ['billing.plans.developer.stripe_price', ''],
    'missing Pro price' => ['billing.plans.pro.stripe_price', ''],
    'missing Team price' => ['billing.plans.team.stripe_price', ''],
]);

test('checkout and portal use tenant customer and do not enable a plan from browser redirects', function () {
    billingConfig();
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    $user = newUser(); $this->actingAs($user);
    $fail = false;
    Http::fake(function ($req) use (&$fail) {
        if ($fail) return Http::response(['error' => 'sensitive-provider-error'], 500);
        if (str_ends_with($req->url(), '/customers')) return Http::response(['id' => 'cus_fixture', 'livemode' => false]);
        if (str_ends_with($req->url(), '/checkout/sessions')) return Http::response(['url' => 'https://checkout.stripe.com/fixture', 'livemode' => false]);
        return Http::response(['url' => 'https://billing.stripe.com/fixture', 'livemode' => false]);
    });
    $this->postJson('/api/billing/checkout', ['plan' => 'bad'])->assertUnprocessable();
    $this->postJson('/api/billing/portal')->assertStatus(409);
    $this->postJson('/api/billing/checkout', ['plan' => 'developer'])->assertOk()->assertJsonPath('url', 'https://checkout.stripe.com/fixture');
    $this->postJson('/api/billing/checkout', ['plan' => 'developer'])->assertOk();
    $this->postJson('/api/billing/portal')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/api/usage')->assertJsonPath('billing_mode', 'test');
    expect(Billing::plan($user->id))->toBe('free');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), 'checkout/sessions') && $r['customer'] === 'cus_fixture' && $r['line_items'][0]['price'] === 'price_dev');
    foreach (Http::recorded() as [$request]) {
        expect($request->hasHeader('Stripe-Version', '2025-03-31.basil'))->toBeTrue();
    }
    DB::table('billing_accounts')->where('user_id', $user->id)->update(['status' => 'active']);
    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertStatus(409);
    DB::table('billing_accounts')->where('user_id', $user->id)->update(['status' => 'none']);
    $fail = true;
    $this->postJson('/api/billing/portal')->assertStatus(503)->assertDontSee('sensitive-provider-error');
});

test('signed webhooks are idempotent and authoritative for out of order events and cancellation', function () {
    billingConfig();
    $user = newUser();
    DB::table('billing_accounts')->insert(['user_id' => $user->id, 'customer_id' => 'cus_fixture', 'plan' => 'free', 'status' => 'none', 'created_at' => now(), 'updated_at' => now()]);
    $subscription = ['livemode' => false, 'id' => 'sub_fixture', 'customer' => 'cus_fixture', 'status' => 'active', 'items' => ['data' => [['price' => ['id' => 'price_pro'], 'current_period_end' => now()->addMonth()->timestamp]]]];
    Http::fake(function () use (&$subscription) { return Http::response($subscription); });
    $event = ['id' => 'evt_fixture', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_fixture', 'status' => 'canceled']]];
    $this->postJson('/stripe/webhook', $event)->assertStatus(400);
    signedEvent($this, $event + ['livemode' => true])->assertStatus(400);
    signedEvent($this, $event + ['livemode' => null])->assertStatus(400);
    signedEvent($this, $event, -600)->assertStatus(400);
    Http::assertNothingSent();
    signedEvent($this, $event)->assertOk();
    expect(Billing::plan($user->id))->toBe('pro');
    signedEvent($this, $event)->assertOk(); Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r->hasHeader('Stripe-Version', '2025-03-31.basil'));
    $periodEnd = $subscription['items']['data'][0]['current_period_end'];
    $item = $subscription['items']['data'][0];
    foreach ([[], [$item, $item], [['price' => ['id' => 'price_unknown'], 'current_period_end' => $periodEnd]], [['price' => ['id' => 'price_pro']]]] as $i => $items) {
        $subscription['items']['data'] = $items;
        $subscription['current_period_end'] = $periodEnd; // Legacy field must not authorize access.
        $event['id'] = 'evt_invalid_items_'.$i;
        signedEvent($this, $event)->assertOk();
        expect(Billing::plan($user->id))->toBe('free');
    }
    $subscription['items']['data'] = [$item];
    $event['id'] = 'evt_restored';
    signedEvent($this, $event)->assertOk();
    expect(Billing::plan($user->id))->toBe('pro');
    // A delayed created event must not overwrite the authoritative canceled provider state.
    $subscription['status'] = 'canceled';
    $event['id'] = 'evt_late'; $event['type'] = 'customer.subscription.created';
    signedEvent($this, $event)->assertOk();
    expect(Billing::plan($user->id))->toBe('free');
    $event['id'] = 'evt_deleted'; $event['type'] = 'customer.subscription.deleted'; signedEvent($this, $event)->assertOk();
    signedEvent($this, ['id' => 'evt_other', 'type' => 'invoice.paid'])->assertOk();
    signedEvent($this, ['id' => 'evt_bad', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => '../customers']]])->assertStatus(400);
    signedEvent($this, ['invalid' => true])->assertStatus(400);
});

test('preflight reports only configuration checks without calling the provider', function () {
    Http::fake();
    billingConfig();
    $this->artisan('billing:preflight')
        ->expectsOutput('PASS API key matches Stripe mode')
        ->expectsOutput('Local configuration only. Provider access, price details, webhook delivery, and portal are not verified.')
        ->assertExitCode(0);
    config(['billing.stripe_mode' => 'live']);
    $this->artisan('billing:preflight')->expectsOutput('FAIL API key matches Stripe mode')->assertExitCode(1);
    Http::assertNothingSent();
});

test('test-mode pricing discloses that no real payment will be taken', function () {
    billingConfig();
    $this->get('/pricing')->assertInertia(fn ($page) => $page
        ->where('page.billing_enabled', true)
        ->where('page.billing_note', 'Stripe test mode. Use test payment details only; no real payment will be taken.'));
});

test('checkout rejects provider responses in the wrong mode and invalid session links', function () {
    billingConfig();
    $user = newUser();
    $this->actingAs($user);
    $customerLive = true;
    Http::fake(function ($request) use (&$customerLive) {
        return str_ends_with($request->url(), '/customers')
            ? Http::response(['id' => 'cus_fixture', 'livemode' => $customerLive])
            : Http::response(['url' => 'https://example.invalid/session', 'livemode' => false]);
    });
    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertStatus(503);
    $this->assertDatabaseHas('billing_accounts', ['user_id' => $user->id, 'customer_id' => null]);
    Http::assertSentCount(1);
    $customerLive = false;
    $this->postJson('/api/billing/checkout', ['plan' => 'pro'])->assertStatus(503);
    $this->postJson('/api/billing/portal')->assertStatus(503);
    expect(Billing::plan($user->id))->toBe('free');
});

test('provider failures and wrong-mode subscriptions do not consume a retryable event', function () {
    billingConfig();
    $user = newUser();
    DB::table('billing_accounts')->insert(['user_id' => $user->id, 'customer_id' => 'cus_fixture', 'plan' => 'free', 'status' => 'none', 'created_at' => now(), 'updated_at' => now()]);
    $subscription = ['livemode' => false, 'id' => 'sub_fixture', 'customer' => 'cus_fixture', 'status' => 'active', 'items' => ['data' => [['price' => ['id' => 'price_pro'], 'current_period_end' => now()->addMonth()->timestamp]]]];
    Http::fake(['https://api.stripe.com/v1/subscriptions/sub_fixture' => Http::sequence()
        ->push([], 503)->push(array_replace($subscription, ['livemode' => true]))->push($subscription)]);
    $event = ['id' => 'evt_retry', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_fixture']]];
    signedEvent($this, $event)->assertStatus(503);
    signedEvent($this, $event)->assertStatus(503);
    $this->assertDatabaseMissing('billing_events', ['id' => 'evt_retry']);
    expect(Billing::plan($user->id))->toBe('free');
    signedEvent($this, $event)->assertOk();
    signedEvent($this, $event)->assertOk();
    Http::assertSentCount(3);
    expect(Billing::plan($user->id))->toBe('pro');
});

test('encryption migration upgrades legacy destination rows without double encryption', function () {
    Queue::fake(); [$e, $token] = sampleEndpoint($this);
    DB::table('forward_targets')->insert(['endpoint_id' => $e, 'url' => 'https://93.184.216.34/legacy', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $migration = require database_path('migrations/2026_11_14_000002_encrypt_destinations.php');
    $migration->up();
    $cipher = DB::table('forward_targets')->where('endpoint_id', $e)->value('url');
    expect(Crypt::decryptString($cipher))->toBe('https://93.184.216.34/legacy');
    $migration->up(); $migration->down();
    expect(DB::table('forward_targets')->where('endpoint_id', $e)->value('url'))->toBe($cipher);
});
