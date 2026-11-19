<?php

namespace App\Jobs;

use App\Support\SafeHttp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * Exponential backoff schedule in seconds for up to 5 tries.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 90, 270];

    public function __construct(public int $deliveryId)
    {
    }

    public function handle(): void
    {
        $delivery = DB::table('deliveries')->where('id', $this->deliveryId)->first();
        if (! $delivery || in_array($delivery->status, ['succeeded', 'blocked', 'failed'], true)) {
            return;
        }

        $capture = DB::table('captures')->where('id', $delivery->capture_id)->first();
        if (! $capture) {
            DB::table('deliveries')->where('id', $delivery->id)->update([
                'status' => 'failed',
                'last_error' => 'Captured request expired or was deleted.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            return;
        }

        // Check SSRF target validation first. If target violates SSRF policy, mark blocked immediately and do not retry.
        try {
            SafeHttp::validateTarget(SafeHttp::destination($delivery->target_url));
        } catch (\Throwable $e) {
            $errorMsg = SafeHttp::sanitizeErrorMessage($e->getMessage());
            $attemptNo = ((int) $delivery->attempt_count) + 1;
            DB::table('delivery_attempts')->insert([
                'delivery_id' => $delivery->id,
                'attempt_number' => $attemptNo,
                'status_code' => null,
                'duration_ms' => 0,
                'response_excerpt' => '',
                'error' => $errorMsg,
                'created_at' => now(),
            ]);
            DB::table('deliveries')->where('id', $delivery->id)->update([
                'status' => 'blocked',
                'attempt_count' => $attemptNo,
                'last_status_code' => null,
                'last_duration_ms' => 0,
                'last_error' => $errorMsg,
                'next_attempt_at' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            return;
        }

        $payload = json_decode(Crypt::decryptString($capture->payload), true) ?: [];
        $rawBody = isset($payload['body_base64']) ? (string) base64_decode((string) $payload['body_base64'], true) : '';
        $headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
        if (! empty($capture->content_type)) {
            $headers['Content-Type'] = $capture->content_type;
        }

        $targetUrl = SafeHttp::replayUrl(SafeHttp::destination($delivery->target_url), is_array($payload['query'] ?? null) ? $payload['query'] : []);
        $result = SafeHttp::send($capture->method, $targetUrl, $headers, $rawBody);
        $attemptNo = ((int) $delivery->attempt_count) + 1;

        DB::table('delivery_attempts')->insert([
            'delivery_id' => $delivery->id,
            'attempt_number' => $attemptNo,
            'status_code' => $result['status_code'],
            'duration_ms' => $result['duration_ms'],
            'response_excerpt' => substr((string) $result['response_excerpt'], 0, 1024),
            'error' => $result['error'],
            'created_at' => now(),
        ]);

        if ($result['ok']) {
            DB::table('deliveries')->where('id', $delivery->id)->update([
                'status' => 'succeeded',
                'attempt_count' => $attemptNo,
                'last_status_code' => $result['status_code'],
                'last_duration_ms' => $result['duration_ms'],
                'last_error' => null,
                'next_attempt_at' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            if ($delivery->kind === 'forward') {
                DB::table('forward_targets')->where('endpoint_id', $delivery->endpoint_id)->where('url', $delivery->target_url)->update([
                    'consecutive_failures' => 0,
                    'disabled_until' => null,
                    'updated_at' => now(),
                ]);
            }
            return;
        }

        // Check if redirect or SSRF block occurred mid-flight
        if ($result['status_code'] !== null && $result['status_code'] >= 300 && $result['status_code'] < 400) {
            DB::table('deliveries')->where('id', $delivery->id)->update([
                'status' => 'blocked',
                'attempt_count' => $attemptNo,
                'last_status_code' => $result['status_code'],
                'last_duration_ms' => $result['duration_ms'],
                'last_error' => $result['error'],
                'next_attempt_at' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            return;
        }

        // Record forward target consecutive failure & trip circuit breaker after 5 consecutive failures
        if ($delivery->kind === 'forward') {
            DB::transaction(function () use ($delivery) {
                $target = DB::table('forward_targets')->where('endpoint_id', $delivery->endpoint_id)
                    ->where('url', $delivery->target_url)->lockForUpdate()->first();
                if ($target) {
                    $failures = min(32767, (int) $target->consecutive_failures + 1);
                    DB::table('forward_targets')->where('id', $target->id)->update([
                        'consecutive_failures' => $failures,
                        'disabled_until' => $failures >= 5 ? now()->addMinutes(15) : $target->disabled_until,
                        'updated_at' => now(),
                    ]);
                }
            });
        }

        if ($attemptNo >= $this->tries) {
            DB::table('deliveries')->where('id', $delivery->id)->update([
                'status' => 'failed',
                'attempt_count' => $attemptNo,
                'last_status_code' => $result['status_code'],
                'last_duration_ms' => $result['duration_ms'],
                'last_error' => $result['error'] ?? 'Delivery failed after maximum retry attempts.',
                'next_attempt_at' => null,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            return;
        }

        $delaySeconds = $this->backoff[$attemptNo - 1] ?? 270;
        DB::table('deliveries')->where('id', $delivery->id)->update([
            'status' => 'retrying',
            'attempt_count' => $attemptNo,
            'last_status_code' => $result['status_code'],
            'last_duration_ms' => $result['duration_ms'],
            'last_error' => $result['error'] ?? 'Temporary delivery failure; retry scheduled.',
            'next_attempt_at' => now()->addSeconds($delaySeconds),
            'updated_at' => now(),
        ]);

        if ($this->job) {
            $this->release($delaySeconds);
        }
    }
}
