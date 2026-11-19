<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The untasked.run router sets Host to the fly.dev upstream for Fly routing and
 * forwards the visitor's host in X-Untasked-Original-Host. That header is only
 * honoured when its value matches an allow-list (TRUSTED_ORIGINAL_HOSTS, default
 * "*.untasked.run"), so a client cannot spoof it to poison generated URLs or
 * redirects. X-Forwarded-Host is never trusted (see bootstrap/app.php).
 */
class PreferBrandedHost
{
    public static function allowed(string $host): bool
    {
        $patterns = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_ORIGINAL_HOSTS', '*.untasked.run'))));
        foreach ($patterns as $pattern) {
            $pattern = strtolower($pattern);
            if ($pattern === $host) return true;
            if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1) return true;
        }
        return false;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $original = strtolower(trim((string) $request->headers->get('X-Untasked-Original-Host', '')));
        if ($original !== ''
            && ! str_contains($original, '/')
            && ! str_contains($original, ' ')
            && filter_var($original, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            && self::allowed($original)
        ) {
            $request->headers->set('Host', $original);
            $request->server->set('HTTP_HOST', $original);
            $request->server->set('SERVER_NAME', $original);
        }

        return $next($request);
    }
}
