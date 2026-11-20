<?php

use App\Support\Health;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::forget(Health::WORKER_KEY);
    Cache::forget(Health::SCHEDULER_KEY);
});

it('reports ready when the database answers and both processes are fresh', function () {
    Cache::put(Health::WORKER_KEY, time(), now()->addHour());
    Cache::put(Health::SCHEDULER_KEY, time(), now()->addHour());

    $this->getJson('/api/health')
        ->assertOk()
        ->assertHeaderMissing('ETag')
        ->assertHeader('Cache-Control')
        ->assertExactJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'worker' => 'ok', 'scheduler' => 'ok'],
        ]);
});

it('reports unavailable with 503 and no internals when the database fails', function () {
    DB::shouldReceive('select')->andThrow(new RuntimeException('SQLSTATE[HY000] password=secret host=db.internal'));

    $response = $this->getJson('/api/health')->assertStatus(503)
        ->assertJsonPath('status', 'unavailable')
        ->assertJsonPath('checks.database', 'unavailable');

    expect($response->getContent())
        ->not->toContain('secret')
        ->not->toContain('db.internal')
        ->not->toContain('SQLSTATE');
});

it('stays HTTP 200 but degraded when the worker or scheduler never reported', function () {
    $this->getJson('/api/health')->assertOk()
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.worker', 'unknown')
        ->assertJsonPath('checks.scheduler', 'unknown');
});

it('marks a process stale when its heartbeat is older than the limit', function () {
    $old = time() - Health::STALE_AFTER_SECONDS - 60;
    Cache::put(Health::WORKER_KEY, $old, now()->addHour());
    Cache::put(Health::SCHEDULER_KEY, time(), now()->addHour());

    expect(Health::report()['checks'])->toMatchArray(['worker' => 'stale', 'scheduler' => 'ok'])
        ->and(Health::report()['status'])->toBe('degraded');
});

it('treats a non-numeric heartbeat as unknown and a cache outage as unknown without throwing', function () {
    Cache::put(Health::WORKER_KEY, 'garbage', now()->addHour());
    expect(Health::report()['checks']['worker'])->toBe('unknown');

    Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
    expect(Health::report()['checks'])->toMatchArray(['worker' => 'unknown', 'scheduler' => 'unknown']);
});

it('records heartbeats and ignores cache write failures', function () {
    Health::heartbeat(Health::SCHEDULER_KEY, 1_700_000_000);
    expect(Cache::get(Health::SCHEDULER_KEY))->toBe(1_700_000_000);

    Cache::shouldReceive('put')->andThrow(new RuntimeException('redis down'));
    Health::heartbeat(Health::SCHEDULER_KEY, 1_700_000_100);
    expect(Health::isReady())->toBeTrue();
});
