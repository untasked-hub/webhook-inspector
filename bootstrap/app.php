<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PreferBrandedHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::prefix('api/v1')->middleware(['throttle:180,1', \App\Http\Middleware\ProjectBearerToken::class])->group(function () {
                $controller = \App\Http\Controllers\ProjectApiController::class;
                \Illuminate\Support\Facades\Route::get('/projects/{project}/endpoints', [$controller, 'endpoints']);
                \Illuminate\Support\Facades\Route::get('/endpoints/{endpoint}/requests', [$controller, 'history']);
                \Illuminate\Support\Facades\Route::get('/requests/{capture}', [$controller, 'detail']);
                \Illuminate\Support\Facades\Route::post('/requests/{capture}/replay', [$controller, 'replay']);
            });
            \Illuminate\Support\Facades\Route::post('/stripe/webhook', [\App\Http\Controllers\BillingController::class, 'webhook']);
            // Alias matching the Stripe Sandbox destination configured by the operator.
            \Illuminate\Support\Facades\Route::post('/billing/stripe/webhook', [\App\Http\Controllers\BillingController::class, 'webhook']);
            \Illuminate\Support\Facades\Route::any('/w/{token}', [\App\Http\Controllers\InspectorController::class, 'capture'])
                ->where('token', '[A-Za-z0-9]{48}');
            \Illuminate\Support\Facades\Route::any('/c/{token}', [\App\Http\Controllers\InspectorController::class, 'capture'])
                ->where('token', '[A-Za-z0-9]{48}');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Fly terminates TLS and forwards plain HTTP with X-Forwarded-Proto.
        // Without this, Ziggy/Inertia emit http:// URLs and browsers block XHR as mixed content.
        $middleware->trustProxies(
            at: '*',
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        ); // X-Forwarded-Host intentionally NOT trusted (host spoofing)
        // Do not call trustHosts / restrict accepted hosts. The app may be reached on
        // fly.dev, branded *.untasked.run, custom domains, or another provider after
        // the owner downloads the code. Fly only routes hostnames with a cert on this app.
        // Keep cookies host-only: never set SESSION_DOMAIN to the parent branded domain.
        // No login route in the starter — guests on auth routes go home instead of RouteNotFoundException 500.
        $middleware->redirectGuestsTo('/login');
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Runs globally and first, so auth redirects and generated URLs see the vetted host.
        $middleware->prepend(PreferBrandedHost::class);
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (\Illuminate\Http\Request $request, \Throwable $e) => $request->is('api/v1/*') || $request->expectsJson());
    })->create();

$app->booted(function () {
    // Force https scheme only. Do not forceRootUrl — the app is served from multiple
    // hosts (branded, fly.dev, candidate, custom domains) and asset/Ziggy URLs must
    // follow the request Host (PreferBrandedHost + trustProxies). The branded router
    // keeps X-Untasked-Original-Host because Fly's second hop often overwrites
    // X-Forwarded-Host with fly.dev.
    $url = config('app.url');
    if (is_string($url) && str_starts_with($url, 'https://')) {
        URL::forceScheme('https');
    }

    // Root-relative Vite URLs stay same-origin on every public host (avoids CORS blank pages).
    Vite::createAssetPathsUsing(function (string $path, ?bool $secure = null): string {
        return str_starts_with($path, '/') ? $path : '/'.$path;
    });
});

return $app;
