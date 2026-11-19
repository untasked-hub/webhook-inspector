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

function billingConfig(): void {
    config(['billing.managed' => true, 'billing.secret' => 'sk_test_fixture', 'billing.webhook_secret' => 'whsec_fixture', 'billing.plans.developer.stripe_price' => 'price_dev', 'billing.plans.pro.stripe_price' => 'price_pro', 'billing.plans.team.stripe_price' => 'price_team']);
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
    $body = json_encode($event); $t = time() + $offset;
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
    $this->get('/pricing')->assertInertia(fn ($page) => $page->where('page.billing_note', 'Checkout is available.'));
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

test('billing disabled state and authentication fail closed', function () {
    config(['billing.managed' => true, 'billing.secret' => null]);
    $this->postJson('/api/billing/checkout', ['plan' => 'developer'])->assertUnauthorized();
    $this->actingAs(newUser());
    $this->postJson('/api/billing/checkout', ['plan' => 'developer'])->assertStatus(503);
    $this->postJson('/api/billing/portal')->assertStatus(503);
    $this->postJson('/stripe/webhook')->assertStatus(503);
    $this->getJson('/api/usage')->assertJsonPath('billing_enabled', false);
});

test('checkout and portal use tenant customer and do not enable a plan from browser redirects', function () {
    billingConfig();
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    $user = newUser(); $this->actingAs($user);
    $fail = false;
    Http::fake(function ($req) use (&$fail) {
        if ($fail) return Http::response(['error' => 'sensitive-provider-error'], 500);
        if (str_ends_with($req->url(), '/customers')) return Http::response(['id' => 'cus_fixture']);
        if (str_ends_with($req->url(), '/checkout/sessions')) return Http::response(['url' => 'https://checkout.stripe.com/fixture']);
        return Http::response(['url' => 'https://billing.stripe.com/fixture']);
    });
    $this->postJson('/api/billing/checkout', ['plan' => 'bad'])->assertUnprocessable();
    $this->postJson('/api/billing/portal')->assertStatus(409);
    $this->postJson('/api/billing/checkout', ['plan' => 'developer'])->assertOk()->assertJsonPath('url', 'https://checkout.stripe.com/fixture');
    $this->postJson('/api/billing/checkout', ['plan' => 'developer'])->assertOk();
    $this->postJson('/api/billing/portal')->assertOk();
    expect(Billing::plan($user->id))->toBe('free');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), 'checkout/sessions') && $r['customer'] === 'cus_fixture' && $r['line_items'][0]['price'] === 'price_dev');
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
    $subscription = ['id' => 'sub_fixture', 'customer' => 'cus_fixture', 'status' => 'active', 'current_period_end' => now()->addMonth()->timestamp, 'items' => ['data' => [['price' => ['id' => 'price_pro']]]]];
    Http::fake(function () use (&$subscription) { return Http::response($subscription); });
    $event = ['id' => 'evt_fixture', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_fixture', 'status' => 'canceled']]];
    $this->postJson('/stripe/webhook', $event)->assertStatus(400);
    signedEvent($this, $event, -600)->assertStatus(400);
    signedEvent($this, $event)->assertOk();
    expect(Billing::plan($user->id))->toBe('pro');
    signedEvent($this, $event)->assertOk(); Http::assertSentCount(1);
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
