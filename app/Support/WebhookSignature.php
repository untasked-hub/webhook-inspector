<?php

namespace App\Support;

use Illuminate\Http\Request;

final class WebhookSignature
{
    public static function verify(Request $request, string $body, ?string $secret, string $provider): array
    {
        if ($secret === null || $secret === '') return ['status' => 'not_configured', 'provider' => null];
        $headers = ['stripe' => 'Stripe-Signature', 'github' => 'X-Hub-Signature-256', 'slack' => 'X-Slack-Signature', 'hmac-sha256' => 'X-Webhook-Signature'];
        if ($provider === 'auto') {
            $provider = 'hmac-sha256';
            foreach ($headers as $mode => $header) {
                if ($request->hasHeader($header)) { $provider = $mode; break; }
            }
        }
        $signature = $request->header($headers[$provider], '');
        $valid = false;
        if ($signature !== '') {
            if ($provider === 'stripe') {
                $parts = [];
                foreach (explode(',', $signature) as $part) {
                    $pair = explode('=', trim($part), 2);
                    if (count($pair) === 2) $parts[$pair[0]][] = $pair[1];
                }
                $timestamp = $parts['t'][0] ?? '';
                if (ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300) {
                    $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
                    foreach ($parts['v1'] ?? [] as $candidate) $valid = hash_equals($expected, $candidate) || $valid;
                }
            } elseif ($provider === 'slack') {
                $timestamp = $request->header('X-Slack-Request-Timestamp', '');
                $valid = ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300 && hash_equals('v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, $secret), $signature);
            } else {
                $expected = hash_hmac('sha256', $body, $secret);
                $valid = hash_equals(($provider === 'github' ? 'sha256=' : '').$expected, $signature);
            }
        }
        return ['status' => $signature === '' ? 'missing' : ($valid ? 'verified' : 'invalid'), 'provider' => $provider];
    }
}
