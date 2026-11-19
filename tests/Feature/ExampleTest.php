<?php

test('home renders Welcome', function () {
    $this->get('/')->assertOk()->assertSee('Webhook Inspector');
});

use App\Jobs\DeliverWebhook;
use App\Support\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('guest on dashboard is redirected to login and security headers are set', function () {
    $this->get('/dashboard')
        ->assertRedirect('/login')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
    $this->get('/login')->assertOk();
    $this->get('/register')->assertOk();
});

test('auth, tenant isolation, encrypted capture, redaction, limits, and history work', function () {
    $this->post('/register', [
        'name' => 'Alice', 'email' => 'alice@example.com',
        'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();

    $p = $this->postJson('/api/projects', ['name' => 'Billing'])->assertCreated()->json('id');
    $e = $this->postJson('/api/endpoints', ['project_id' => $p, 'name' => 'Stripe', 'retention_days' => 3])->assertCreated()->json('id');
    $token = $this->getJson('/api/projects')->assertOk()->json('endpoints.0.token');
    expect(strlen($token))->toBe(48);

    $this->postJson("/c/{$token}?secret=top&ok=1", ['event' => 'paid'], ['Authorization' => 'Bearer abc', 'X-Custom' => 'yes'])
        ->assertStatus(202);
    $raw = DB::table('captures')->first()->payload;
    expect($raw)->not->toContain('paid')->not->toContain('abc');

    $list = $this->getJson("/api/endpoints/{$e}/requests?method=POST")->assertOk()->json('requests');
    expect($list)->toHaveCount(1);
    $detail = $this->getJson("/api/endpoints/{$e}/requests/{$list[0]['id']}")->assertOk()->json('payload');
    expect(base64_decode($detail['body_base64']))->toContain('paid')
        ->and($detail['headers']['authorization'][0])->toBe('[redacted]')
        ->and($detail['query']['secret'])->toBe('[redacted]')
        ->and($detail['query']['ok'])->toBe('1');

    // Oversized payload rejected with 413
    $this->call('POST', "/c/{$token}", [], [], [], ['CONTENT_LENGTH' => 1048577], str_repeat('a', 1048577))->assertStatus(413);

    // Tenant isolation: Bob cannot read or clear Alice's endpoint
    $this->post('/logout')->assertRedirect('/');
    $this->post('/login', ['email' => 'alice@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
    $this->post('/register', [
        'name' => 'Bob', 'email' => 'bob@example.com',
        'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect('/dashboard');
    $this->getJson("/api/endpoints/{$e}/requests")->assertNotFound();
    $this->postJson('/api/endpoints', ['project_id' => $p, 'name' => 'Steal'])->assertNotFound();
    $this->deleteJson("/api/endpoints/{$e}")->assertNotFound();

    // Alice clears and deletes her endpoint
    $this->post('/logout');
    $this->post('/login', ['email' => 'alice@example.com', 'password' => 'correct-horse-battery'])->assertRedirect('/dashboard');
    $this->deleteJson("/api/endpoints/{$e}/requests")->assertNoContent();
    $this->deleteJson("/api/endpoints/{$e}")->assertNoContent();
});

test('SafeHttp enforces strict SSRF policy, header stripping, error sanitization, and send boundaries', function () {
    expect(SafeHttp::isPublicIp('93.184.216.34'))->toBeTrue()
        ->and(SafeHttp::isPublicIp('8.8.8.8'))->toBeTrue()
        ->and(SafeHttp::isPublicIp('invalid-ip'))->toBeFalse()
        ->and(SafeHttp::isPublicIp('127.0.0.1'))->toBeFalse()
        ->and(SafeHttp::isPublicIp('10.0.0.5'))->toBeFalse()
        ->and(SafeHttp::isPublicIp('100.64.0.1'))->toBeFalse()
        ->and(SafeHttp::isPublicIp('169.254.169.254'))->toBeFalse()
        ->and(SafeHttp::isPublicIp('172.16.0.1'))->toBeFalse()
        ->and(SafeHttp::isPublicIp('192.168.1.1'))->toBeFalse()
        ->and(SafeHttp::isPublicIp('224.0.0.1'))->toBeFalse()
        ->and(SafeHttp::isPublicIp('::1'))->toBeFalse();

    $valid = SafeHttp::validateTarget('https://93.184.216.34:8443/hook');
    expect($valid['ip'])->toBe('93.184.216.34')
        ->and($valid['port'])->toBe(8443)
        ->and($valid['scheme'])->toBe('https');

    $validCustom = SafeHttp::validateTarget('http://example.com/webhook', fn () => ['93.184.216.34']);
    expect($validCustom['ip'])->toBe('93.184.216.34')->and($validCustom['port'])->toBe(80);

    expect(fn () => SafeHttp::validateTarget(''))->toThrow(\RuntimeException::class);
    expect(fn () => SafeHttp::validateTarget('not-a-url'))->toThrow(\RuntimeException::class);
    expect(fn () => SafeHttp::validateTarget('https://user:pass@93.184.216.34/hook'))->toThrow(\RuntimeException::class);
    expect(fn () => SafeHttp::validateTarget('ftp://93.184.216.34/hook'))->toThrow(\RuntimeException::class);
    expect(fn () => SafeHttp::validateTarget('https://93.184.216.34:22/hook'))->toThrow(\RuntimeException::class);
    expect(fn () => SafeHttp::validateTarget('http://localhost/hook'))->toThrow(\RuntimeException::class);
    expect(fn () => SafeHttp::validateTarget('http://metadata.google.internal/hook'))->toThrow(\RuntimeException::class);
    expect(fn () => SafeHttp::validateTarget('http://service.internal/hook'))->toThrow(\RuntimeException::class);
    expect(fn () => SafeHttp::validateTarget('http://example.com/hook', fn () => []))->toThrow(\RuntimeException::class);
    expect(fn () => SafeHttp::validateTarget('http://example.com/hook', fn () => ['93.184.216.34', '10.0.0.1']))->toThrow(\RuntimeException::class);

    expect(SafeHttp::resolveHostIps('127.0.0.1'))->toBeArray();

    $sanitized = SafeHttp::sanitizeHeaders([
        'Authorization' => ['Bearer secret'],
        'Cookie' => ['session=123'],
        'X-Stripe-Signature' => ['t=123,v1=abc'],
        'X-Api-Key' => ['my-key'],
        'Content-Type' => ['application/json'],
        'X-Custom-Event' => 'order.created',
    ]);
    expect($sanitized)->toHaveKey('Content-Type', 'application/json')
        ->toHaveKey('X-Custom-Event', 'order.created')
        ->toHaveKey('User-Agent', 'WebhookInspector-Forwarder/1.0')
        ->not->toHaveKey('Authorization')
        ->not->toHaveKey('Cookie')
        ->not->toHaveKey('X-Stripe-Signature')
        ->not->toHaveKey('X-Api-Key');

    expect(SafeHttp::sanitizeErrorMessage('Failed connecting to https://internal.corp:8443/secret?token=123 now'))
        ->toBe('Failed connecting to [redacted-url] now');

    $oversized = SafeHttp::send('POST', 'https://93.184.216.34/hook', [], str_repeat('x', SafeHttp::MAX_REQUEST_BYTES + 1));
    expect($oversized['ok'])->toBeFalse()->and($oversized['error'])->toContain('exceeds 1 MiB');

    $blockedSend = SafeHttp::send('POST', 'http://127.0.0.1/hook', ['X-Test' => '1'], '{"a":1}');
    expect($blockedSend['ok'])->toBeFalse()->and($blockedSend['error'])->toContain('private, reserved, or disallowed');
});

test('forwarding, replay, delivery retries, circuit breaker, and retention retroactive tightening and purge work', function () {
    Queue::fake();

    $this->post('/register', [
        'name' => 'Dev', 'email' => 'dev@example.com',
        'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
    ])->assertRedirect('/dashboard');

    $p = $this->postJson('/api/projects', ['name' => 'Core'])->assertCreated()->json('id');
    $e = $this->postJson('/api/endpoints', ['project_id' => $p, 'name' => 'Hooks', 'retention_days' => 7])->assertCreated()->json('id');
    $token = $this->getJson('/api/projects')->assertOk()->json('endpoints.0.token');

    // SSRF rejection on forward target save
    $this->putJson("/api/endpoints/{$e}/forward", ['url' => 'http://127.0.0.1:8080/hook', 'enabled' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('url');
    $this->putJson("/api/endpoints/{$e}/forward", ['url' => 'http://169.254.169.254/latest/meta-data', 'enabled' => true])
        ->assertStatus(422);

    // Valid public IP forward target save & read
    $this->putJson("/api/endpoints/{$e}/forward", ['url' => 'https://93.184.216.34:8443/webhook', 'enabled' => true])
        ->assertNoContent();
    $fwd = $this->getJson("/api/endpoints/{$e}/forward")->assertOk()->json('target');
    expect($fwd['url'])->toBe('https://93.184.216.34:8443/[hidden-path]')->and((bool) $fwd['enabled'])->toBeTrue();

    // Capture enqueues automatic forward delivery
    $this->postJson("/c/{$token}", ['hello' => 'world'])->assertStatus(202);
    Queue::assertPushed(DeliverWebhook::class, 1);

    $captureId = $this->getJson("/api/endpoints/{$e}/requests")->assertOk()->json('requests.0.id');

    // Replay rejects SSRF and accepts valid public destination
    $this->postJson("/api/endpoints/{$e}/requests/{$captureId}/replay", ['url' => 'http://localhost/admin'])
        ->assertStatus(422);
    $replayRes = $this->postJson("/api/endpoints/{$e}/requests/{$captureId}/replay", ['url' => 'https://93.184.216.34/replay', 'confirm' => true])
        ->assertStatus(202)
        ->json('id');
    expect($replayRes)->toBeInt();

    // DeliverWebhook job: blocked when target is mutated to private IP before execution
    DB::table('deliveries')->where('id', $replayRes)->update(['target_url' => 'http://10.0.0.1/internal']);
    (new DeliverWebhook($replayRes))->handle();
    $blockedRow = DB::table('deliveries')->where('id', $replayRes)->first();
    expect($blockedRow->status)->toBe('blocked')->and((int) $blockedRow->attempt_count)->toBe(1);

    // Captures are protected by a foreign key; expired captures cascade their deliveries.
    $forwardDelivery = DB::table('deliveries')->where('endpoint_id', $e)->where('kind', 'forward')->first();
    expect($forwardDelivery)->not->toBeNull();

    // SafeHttp::send success path and DeliverWebhook success path (resets consecutive_failures & signs HMAC)
    SafeHttp::fakeTransport(fn ($m, $t, $h, $b) => ['status' => 202, 'body' => 'accepted-body']);
    DB::table('forward_targets')->where('endpoint_id', $e)->update(['consecutive_failures' => 3]);
    (new DeliverWebhook($forwardDelivery->id))->handle();
    $delivered = DB::table('deliveries')->where('id', $forwardDelivery->id)->first();
    expect($delivered->status)->toBe('succeeded')->and((int) $delivered->last_status_code)->toBe(202);
    expect((int) DB::table('forward_targets')->where('endpoint_id', $e)->value('consecutive_failures'))->toBe(0);

    // Early returns on already-delivered and missing delivery IDs
    (new DeliverWebhook($forwardDelivery->id))->handle();
    (new DeliverWebhook(999999))->handle();

    // Queue another forward delivery via capture to test retry + circuit breaker after 5 consecutive failures
    $this->postJson("/c/{$token}", ['step' => 2])->assertStatus(202);
    $retryDelivery = DB::table('deliveries')->where('endpoint_id', $e)->where('kind', 'forward')->where('status', 'queued')->first();

    // Attempt 1 with 502 Bad Gateway -> retrying
    SafeHttp::fakeTransport(fn () => ['status' => 502, 'body' => 'Bad Gateway']);
    (new DeliverWebhook($retryDelivery->id))->handle();
    $retried = DB::table('deliveries')->where('id', $retryDelivery->id)->first();
    expect($retried->status)->toBe('retrying')->and((int) $retried->attempt_count)->toBe(1);

    // Final attempt 5 with consecutive_failures at 4 -> failed + circuit breaker trips to 5 and disables target
    DB::table('deliveries')->where('id', $retryDelivery->id)->update(['attempt_count' => 4]);
    DB::table('forward_targets')->where('endpoint_id', $e)->update(['consecutive_failures' => 4]);
    SafeHttp::fakeTransport(fn () => ['error' => 'Operation timed out after 5000 milliseconds']);
    (new DeliverWebhook($retryDelivery->id))->handle();
    $final = DB::table('deliveries')->where('id', $retryDelivery->id)->first();
    expect($final->status)->toBe('failed')->and((int) $final->attempt_count)->toBe(5);
    $targetAfterBreak = DB::table('forward_targets')->where('endpoint_id', $e)->first();
    expect($targetAfterBreak->disabled_until)->not->toBeNull()->and((int) $targetAfterBreak->consecutive_failures)->toBe(5);

    // Also test 3xx redirect mid-flight block branch
    $this->postJson("/c/{$token}", ['step' => 3])->assertStatus(202);
    $redirId = $this->postJson("/api/endpoints/{$e}/requests/{$captureId}/replay", ['url' => 'https://93.184.216.34/redir', 'confirm' => true])->assertStatus(202)->json('id');
    SafeHttp::fakeTransport(fn () => ['status' => 302, 'body' => 'Found']);
    (new DeliverWebhook($redirId))->handle();
    expect(DB::table('deliveries')->where('id', $redirId)->value('status'))->toBe('blocked');

    SafeHttp::fakeTransport(null);

    // Deliveries endpoint returns history and attempts
    $delivList = $this->getJson("/api/endpoints/{$e}/deliveries")->assertOk()->json();
    expect($delivList['deliveries'])->not->toBeEmpty()->and($delivList['attempts'])->not->toBeEmpty();

    // Retention retroactive tightening and scheduled purge command
    $this->putJson("/api/endpoints/{$e}/retention", ['retention_days' => 1])->assertNoContent();
    $updatedCapture = DB::table('captures')->where('id', $captureId)->first();
    expect(\Illuminate\Support\Carbon::parse($updatedCapture->expires_at)->lessThanOrEqualTo(now()->addDays(1)->addMinute()))->toBeTrue();

    // Expire capture and run captures:purge
    DB::table('captures')->where('id', $captureId)->update(['expires_at' => now()->subMinute()]);
    $this->artisan('captures:purge')->assertSuccessful();
    expect(DB::table('captures')->where('id', $captureId)->exists())->toBeFalse();
});
