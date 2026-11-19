<?php

use App\Http\Middleware\PreferBrandedHost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function m4User(): User {
    return User::create(['name' => 'Synthetic', 'email' => uniqid('m4_').'@example.invalid', 'password' => bcrypt('Secret123456!')]);
}

function m4Endpoint($test, ?User $user = null): array {
    $user ??= m4User();
    $test->actingAs($user);
    $p = $test->postJson('/api/projects', ['name' => 'Payments'])->assertCreated()->json('id');
    $e = $test->postJson('/api/endpoints', ['name' => 'Stripe', 'project_id' => $p])->assertCreated()->json('id');
    $token = collect($test->getJson('/api/projects')->json('endpoints'))->firstWhere('id', $e)['token'];
    return [$user, $p, $e, $token];
}

test('spoofed X-Untasked-Original-Host outside the allow-list is ignored', function () {
    $res = $this->withHeaders(['X-Untasked-Original-Host' => 'evil.example'])->get('/dashboard');
    $res->assertRedirect();
    expect($res->headers->get('Location'))->not->toContain('evil.example');
    $this->withHeaders(['X-Forwarded-Host' => 'evil.example'])->get('/dashboard')->assertRedirect();
    expect($this->withHeaders(['X-Forwarded-Host' => 'evil.example'])->get('/dashboard')->headers->get('Location'))->not->toContain('evil.example');
});

test('allow-listed branded host is honoured for redirects', function () {
    $res = $this->withHeaders(['X-Untasked-Original-Host' => 'webhook-inspector.untasked.run'])->get('/dashboard');
    expect($res->headers->get('Location'))->toContain('webhook-inspector.untasked.run');
    expect(PreferBrandedHost::allowed('untasked.run'))->toBeFalse();
    expect(PreferBrandedHost::allowed('evil-untasked.run'))->toBeFalse();
    expect(PreferBrandedHost::allowed('a.untasked.run'))->toBeTrue();
});

test('favicon is served', function () {
    $this->get('/favicon.ico')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect(file_exists(public_path('favicon.svg')))->toBeTrue();
});

test('projects can be renamed and deleted with tenant isolation', function () {
    [$alice, $p, $e] = m4Endpoint($this);
    $this->patchJson("/api/projects/{$p}", ['name' => 'Renamed'])->assertOk()->assertJson(['name' => 'Renamed']);
    $this->patchJson("/api/projects/{$p}", ['name' => ''])->assertStatus(422);

    $this->actingAs(m4User());
    $this->patchJson("/api/projects/{$p}", ['name' => 'Hijack'])->assertNotFound();
    $this->deleteJson("/api/projects/{$p}")->assertNotFound();
    $this->getJson('/api/projects')->assertOk()->assertJsonCount(0, 'projects')->assertJsonCount(0, 'endpoints');
    $this->getJson("/api/endpoints/{$e}/requests")->assertNotFound();
    expect(DB::table('projects')->where('id', $p)->value('name'))->toBe('Renamed');

    $this->actingAs($alice);
    $this->deleteJson("/api/projects/{$p}")->assertNoContent();
    expect(DB::table('projects')->where('id', $p)->exists())->toBeFalse();
    expect(DB::table('endpoints')->where('id', $e)->exists())->toBeFalse();
});

test('history supports text search, delivery status filter and CSV export', function () {
    [$user, $p, $e, $token] = m4Endpoint($this);
    $this->postJson("/c/{$token}", ['event' => 'invoice.paid', 'note' => '=HYPERLINK("x")'])->assertStatus(202);
    $this->postJson("/c/{$token}", ['event' => 'customer.created'])->assertStatus(202);
    $this->call('PUT', "/c/{$token}", [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'plain body');

    $this->getJson("/api/endpoints/{$e}/requests?q=INVOICE")->assertOk()->assertJsonCount(1, 'requests');
    $this->getJson("/api/endpoints/{$e}/requests?q=nomatch")->assertOk()->assertJsonCount(0, 'requests');
    $this->getJson("/api/endpoints/{$e}/requests?q=customer&method=POST")->assertOk()->assertJsonCount(1, 'requests');
    $this->getJson("/api/endpoints/{$e}/requests?status=none")->assertOk()->assertJsonCount(3, 'requests');
    $this->getJson("/api/endpoints/{$e}/requests?status=succeeded")->assertOk()->assertJsonCount(0, 'requests');
    $this->getJson("/api/endpoints/{$e}/requests?status=bogus")->assertStatus(422);

    $capture = DB::table('captures')->where('endpoint_id', $e)->orderBy('id')->first();
    DB::table('deliveries')->insert(['endpoint_id' => $e, 'capture_id' => $capture->id, 'kind' => 'replay', 'target_url' => 'x', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson("/api/endpoints/{$e}/requests?status=failed")->assertOk()->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.id', $capture->id);
    $this->getJson("/api/endpoints/{$e}/requests?status=none")->assertOk()->assertJsonCount(2, 'requests');

    $csv = $this->get("/api/endpoints/{$e}/requests?format=csv");
    $csv->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="webhooks.csv"');
    expect($csv->headers->get('Content-Type'))->toContain('text/csv');
    $lines = array_values(array_filter(explode("\n", $csv->getContent())));
    expect($lines[0])->toStartWith('id,method,content_type,size,received_at');
    expect(count($lines))->toBe(4);
    expect($csv->getContent())->not->toContain($token);
    $filtered = $this->get("/api/endpoints/{$e}/requests?format=csv&method=PUT")->getContent();
    expect(count(array_filter(explode("\n", $filtered))))->toBe(2);

    $this->actingAs(m4User());
    $this->get("/api/endpoints/{$e}/requests?format=csv")->assertNotFound();
    $this->getJson("/api/endpoints/{$e}/requests?q=invoice")->assertNotFound();
});

test('analytics reports per-day, per-endpoint, delivery and plan usage scoped to the tenant', function () {
    [$user, $p, $e, $token] = m4Endpoint($this);
    $this->postJson("/c/{$token}", ['a' => 1])->assertStatus(202);
    $this->postJson("/c/{$token}", ['a' => 2])->assertStatus(202);

    $res = $this->getJson('/api/analytics?days=7')->assertOk()->assertJsonCount(7, 'daily')->assertJsonPath('days', 7);
    expect(collect($res->json('daily'))->sum('requests'))->toBe(2);
    expect($res->json('daily.6.requests'))->toBe(2);
    expect($res->json('endpoints.0.requests'))->toBe(2);
    expect($res->json('endpoints.0.project'))->toBe('Payments');
    expect($res->json('usage.requests'))->toBe(2);
    expect($res->json('usage.limit'))->toBeInt();
    $this->getJson('/api/analytics?days=500')->assertStatus(422);

    [$bob] = m4Endpoint($this);
    $other = $this->getJson('/api/analytics')->assertOk()->assertJsonCount(14, 'daily');
    expect(collect($other->json('daily'))->sum('requests'))->toBe(0);
    expect($other->json('endpoints'))->toHaveCount(1);
    expect($other->json('endpoints.0.requests'))->toBe(0);
});

test('analytics requires authentication', function () {
    $this->getJson('/api/analytics')->assertUnauthorized();
});
