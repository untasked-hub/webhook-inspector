<?php

namespace App\Support;

use RuntimeException;

class SafeHttp
{
    public const ALLOWED_SCHEMES = ['http', 'https'];
    public const ALLOWED_PORTS = [80, 443, 8080, 8443];
    public const MAX_REQUEST_BYTES = 1048576; // 1 MiB
    public const MAX_RESPONSE_BYTES = 65536;  // 64 KiB
    public const STRIPPED_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'host',
        'connection',
        'keep-alive',
        'transfer-encoding',
        'upgrade',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    /**
     * Validate a destination URL and check every IPv4 address used for delivery.
     *
     * @param  callable|null  $resolver  Optional custom resolver fn(string $host): array<int, string> for testing
     * @return array{url: string, scheme: string, host: string, port: int, ip: string}
     */
    public static function validateTarget(string $url, ?callable $resolver = null): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new RuntimeException('Destination URL is required and must be at most 2048 characters.');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Invalid destination URL format.');
        }

        if (! empty($parts['user']) || ! empty($parts['pass'])) {
            throw new RuntimeException('Destination URL must not contain embedded credentials.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new RuntimeException('Only http and https destination URLs are permitted.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            throw new RuntimeException('Destination port is not permitted (allowed: 80, 443, 8080, 8443).');
        }

        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        if ($host === '' || in_array($host, ['localhost', 'metadata.google.internal', 'metadata', 'instance-data'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            throw new RuntimeException('Destination host is forbidden by SSRF policy.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = $resolver ? $resolver($host) : self::resolveHostIps($host);
        }

        if (empty($ips)) {
            throw new RuntimeException('Destination host could not be resolved.');
        }

        // Delivery is IPv4-only. Ignore valid AAAA answers, including from injected
        // resolvers, but never skip malformed answers or unsafe A records.
        $ips = array_values(array_filter($ips, static fn ($ip) =>
            ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)));
        if (empty($ips)) {
            throw new RuntimeException('Destination requires a public IPv4 address.');
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp((string) $ip)) {
                throw new RuntimeException('Destination resolves to a private, reserved, or disallowed IP address.');
            }
        }

        return [
            'url' => $url,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'ip' => (string) $ips[0],
        ];
    }

    /**
     * Check that an IPv4 or IPv6 literal is strictly public.
     */
    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Fail closed for IPv6 until the complete IANA special-purpose registry is enforced.
        // IPv6 is never selected by the IPv4-only delivery transport.
        if (str_contains($ip, ':')) return false;
        $unsigned = (int) sprintf('%u', ip2long($ip));
        foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
            ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
            ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
            ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$network, $prefix]) {
            $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
            if (($unsigned & $mask) === (((int) sprintf('%u', ip2long($network))) & $mask)) return false;
        }
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Resolve A records only; the transport explicitly forces and pins IPv4.
     *
     * @return array<int, string>
     */
    public static function resolveHostIps(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (! empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
            }
        }
        if (empty($ips)) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * Strip sensitive headers before forwarding or replaying.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    public static function sanitizeHeaders(array $headers): array
    {
        $clean = [];
        foreach ($headers as $name => $values) {
            $lower = strtolower((string) $name);
            if (in_array($lower, self::STRIPPED_HEADERS, true) || preg_match('/authorization|cookie|token|secret|api[-_]key|signature/i', $lower)) {
                continue;
            }
            $val = is_array($values) ? implode(', ', array_map('strval', $values)) : (string) $values;
            $clean[(string) $name] = substr($val, 0, 2048);
        }
        $clean['User-Agent'] = 'WebhookInspector-Forwarder/1.0';
        return $clean;
    }

    /**
     * Optional transport override for deterministic testing.
     *
     * @var callable|null
     */
    protected static $transportOverride = null;

    public static function fakeTransport(?callable $callback): void
    {
        self::$transportOverride = $callback;
    }

    /**
     * Execute a bounded, redirect-disabled, IP-pinned HTTP request.
     *
     * @param  array<string, mixed>  $headers
     * @return array{ok: bool, status_code: int|null, duration_ms: int, response_excerpt: string, error: string|null}
     */
    public static function send(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        ?callable $resolver = null
    ): array {
        $start = hrtime(true);
        try {
            if (strlen($body) > self::MAX_REQUEST_BYTES) {
                throw new RuntimeException('Outbound request body exceeds 1 MiB limit.');
            }

            $target = self::validateTarget($url, $resolver);
            $cleanHeaders = self::sanitizeHeaders($headers);

            if (self::$transportOverride !== null) {
                $raw = (self::$transportOverride)($method, $target, $cleanHeaders, $body);
                if (! empty($raw['error'])) {
                    throw new RuntimeException((string) $raw['error']);
                }
                $status = (int) ($raw['status'] ?? 200);
            } else {
                // Fail closed: the cURL resolver pin is essential to prevent DNS rebinding.
            if (! function_exists('curl_init')) {
                throw new RuntimeException('Secure delivery requires the PHP cURL extension.');
            }
            $formattedIp = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
            $lines = [];
            foreach ($cleanHeaders as $name => $value) $lines[] = $name.': '.$value;
            $responseBytes = 0;
            $ch = curl_init($target['url']);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $lines, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], $formattedIp)],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBytes): int {
                    $responseBytes += strlen($chunk);
                    return $responseBytes > self::MAX_RESPONSE_BYTES ? 0 : strlen($chunk);
                },
            ]);
                $okTransfer = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                if ($okTransfer === false) throw new RuntimeException($responseBytes > self::MAX_RESPONSE_BYTES ? 'Destination response exceeded the 64 KiB limit.' : $error);
            }
            $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
            // Do not retain potentially sensitive receiver response bodies.
            $excerpt = '';

            if ($status >= 300 && $status < 400) {
                return [
                    'ok' => false,
                    'status_code' => $status,
                    'duration_ms' => $durationMs,
                    'response_excerpt' => $excerpt,
                    'error' => 'Destination returned a redirect ('.$status.'), which is disabled by SSRF policy.',
                ];
            }

            $ok = $status >= 200 && $status < 300;
            return [
                'ok' => $ok,
                'status_code' => $status,
                'duration_ms' => $durationMs,
                'response_excerpt' => $excerpt,
                'error' => $ok ? null : 'Destination responded with HTTP '.$status.'.',
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) round((hrtime(true) - $start) / 1_000_000);
            return [
                'ok' => false,
                'status_code' => null,
                'duration_ms' => $durationMs,
                'response_excerpt' => '',
                'error' => self::sanitizeErrorMessage($e->getMessage()),
            ];
        }
    }

    public static function destination(string $stored): string
    {
        try { return \Illuminate\Support\Facades\Crypt::decryptString($stored); }
        catch (\Illuminate\Contracts\Encryption\DecryptException $e) { return $stored; } // Legacy rows are encrypted by migration.
    }

    public static function displayUrl(string $stored): string
    {
        $parts = parse_url(self::destination($stored));
        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '[hidden]').(isset($parts['port']) ? ':'.$parts['port'] : '').'/[hidden-path]';
    }

    public static function sanitizeQuery(array $query): array
    {
        $clean = [];
        foreach ($query as $name => $value) {
            $clean[$name] = preg_match('/password|token|secret|key|signature/i', (string) $name) ? '[redacted]' : (is_array($value) ? self::sanitizeQuery($value) : $value);
        }
        return $clean;
    }

    public static function replayUrl(string $url, array $query): string
    {
        $clean = [];
        foreach ($query as $name => $value) {
            if (preg_match('/password|token|secret|key|signature/i', (string) $name) || $value === '[redacted]') continue;
            $clean[$name] = is_array($value) ? self::cleanQuery($value) : $value;
        }
        $encoded = http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
        return $url.($encoded === '' ? '' : (str_contains($url, '?') ? '&' : '?').$encoded);
    }

    private static function cleanQuery(array $query): array
    {
        $url = self::replayUrl('https://placeholder.invalid', $query);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $clean);
        return $clean;
    }

    public static function sanitizeErrorMessage(string $message): string
    {
        $clean = preg_replace('/https?:\/\/[^\s]+/i', '[redacted-url]', $message) ?? $message;
        return substr(trim($clean), 0, 280);
    }
}
