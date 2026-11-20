<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Operational readiness for the public health endpoint.
 *
 * The endpoint only ever reports coarse states (ok, degraded, stale, unknown,
 * unavailable). Exception messages, hostnames, versions and configuration are
 * never included in the response.
 */
final class Health
{
    public const WORKER_KEY = 'health:worker';

    public const SCHEDULER_KEY = 'health:scheduler';

    /** A process whose last heartbeat is older than this is reported as stale. */
    public const STALE_AFTER_SECONDS = 180;

    /** Heartbeats are written at most this often to avoid cache churn. */
    private const WRITE_INTERVAL_SECONDS = 30;

    private static int $lastWrite = 0;

    /**
     * Record that a long-running process is alive. Never throws: a cache outage
     * must not take down the worker or scheduler.
     */
    public static function heartbeat(string $key, ?int $now = null): void
    {
        $now ??= time();
        if ($now - self::$lastWrite < self::WRITE_INTERVAL_SECONDS && $key === self::WORKER_KEY) {
            return;
        }
        self::$lastWrite = $now;

        try {
            Cache::put($key, $now, now()->addDay());
        } catch (Throwable) {
            // Heartbeats are best-effort; the health endpoint reports the gap as unknown.
        }
    }

    /**
     * @return array{status: string, checks: array{database: string, worker: string, scheduler: string}}
     */
    public static function report(?int $now = null): array
    {
        $now ??= time();
        $database = self::databaseReady() ? 'ok' : 'unavailable';

        $checks = [
            'database' => $database,
            'worker' => self::processState(self::WORKER_KEY, $now),
            'scheduler' => self::processState(self::SCHEDULER_KEY, $now),
        ];

        if ($database !== 'ok') {
            $status = 'unavailable';
        } elseif ($checks['worker'] === 'ok' && $checks['scheduler'] === 'ok') {
            $status = 'ok';
        } else {
            $status = 'degraded';
        }

        return ['status' => $status, 'checks' => $checks];
    }

    public static function isReady(?int $now = null): bool
    {
        return self::report($now)['status'] !== 'unavailable';
    }

    private static function databaseReady(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function processState(string $key, int $now): string
    {
        try {
            $last = Cache::get($key);
        } catch (Throwable) {
            return 'unknown';
        }

        if (! is_numeric($last)) {
            return 'unknown';
        }

        return $now - (int) $last > self::STALE_AFTER_SECONDS ? 'stale' : 'ok';
    }
}
