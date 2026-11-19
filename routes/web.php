<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InspectorController;
use Illuminate\Support\Facades\Route;

Route::get('/robots.txt', fn () => response("User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /dashboard\nDisallow: /c/\nSitemap: ".url('/sitemap.xml')."\n", 200, ['Content-Type' => 'text/plain']));
Route::get('/sitemap.xml', function () {
    $urls = collect(['/', '/pricing', '/docs', '/self-hosting', '/privacy', '/terms'])->map(fn ($path) => '<url><loc>'.htmlspecialchars(url($path), ENT_XML1).'</loc></url>')->implode('');
    return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>', 200, ['Content-Type' => 'application/xml']);
});
Route::get('/', [DashboardController::class, 'home'])->name('home');
Route::get('/favicon.ico', fn () => response(file_get_contents(public_path('favicon.svg')), 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400']));
Route::get('/{slug}', [\App\Http\Controllers\PagesController::class, 'show'])->whereIn('slug', ['pricing', 'docs', 'self-hosting', 'privacy', 'terms']);
Route::middleware('guest')->group(function () {
    Route::get('/login', [InspectorController::class, 'authPage'])->name('login');
    Route::get('/register', [InspectorController::class, 'authPage']);
    Route::post('/register', [InspectorController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [InspectorController::class, 'login'])->middleware('throttle:10,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/api/usage', [\App\Http\Controllers\BillingController::class, 'usage']);
    Route::post('/api/billing/checkout', [\App\Http\Controllers\BillingController::class, 'checkout'])->middleware('throttle:5,1');
    Route::post('/api/billing/portal', [\App\Http\Controllers\BillingController::class, 'portal'])->middleware('throttle:10,1');
    Route::post('/logout', [InspectorController::class, 'logout']);
    Route::get('/dashboard', [InspectorController::class, 'dashboard'])->name('dashboard');
    Route::post('/api/invitations/accept', [\App\Http\Controllers\ProjectTeamController::class, 'accept'])->middleware('throttle:20,1');
    Route::get('/api/projects/{id}/team', [\App\Http\Controllers\ProjectTeamController::class, 'index']);
    Route::post('/api/projects/{id}/invitations', [\App\Http\Controllers\ProjectTeamController::class, 'invite'])->middleware('throttle:20,1');
    Route::delete('/api/projects/{id}/invitations/{invite}', [\App\Http\Controllers\ProjectTeamController::class, 'revoke']);
    Route::patch('/api/projects/{id}/members/{member}', [\App\Http\Controllers\ProjectTeamController::class, 'update']);
    Route::delete('/api/projects/{id}/members/{member}', [\App\Http\Controllers\ProjectTeamController::class, 'remove']);
    Route::get('/api/projects/{project}/tokens', [\App\Http\Controllers\ProjectApiController::class, 'tokens']);
    Route::post('/api/projects/{project}/tokens', [\App\Http\Controllers\ProjectApiController::class, 'create'])->middleware('throttle:20,1');
    Route::delete('/api/projects/{project}/tokens/{token}', [\App\Http\Controllers\ProjectApiController::class, 'revoke']);
    Route::post('/api/endpoints/{id}/bulk-replay', [InspectorController::class, 'bulkReplay'])->middleware('throttle:5,1');
    Route::get('/api/projects', [InspectorController::class, 'projects']);
    Route::post('/api/projects', [InspectorController::class, 'createProject'])->middleware('throttle:30,1');
    Route::patch('/api/projects/{id}', [InspectorController::class, 'renameProject'])->middleware('throttle:30,1');
    Route::delete('/api/projects/{id}', [InspectorController::class, 'deleteProject']);
    Route::get('/api/analytics', [InspectorController::class, 'analytics']);
    Route::post('/api/endpoints', [InspectorController::class, 'createEndpoint'])->middleware('throttle:30,1');
    Route::delete('/api/endpoints/{id}', [InspectorController::class, 'deleteEndpoint']);
    Route::get('/api/endpoints/{id}/requests', [InspectorController::class, 'history']);
    Route::get('/api/endpoints/{id}/requests/{capture}', [InspectorController::class, 'detail']);
    Route::delete('/api/endpoints/{id}/requests', [InspectorController::class, 'clear']);
    Route::match(['get', 'put'], '/api/endpoints/{id}/receiver-settings', [InspectorController::class, 'receiverSettings'])->middleware('throttle:30,1');
    Route::put('/api/endpoints/{id}/retention', [InspectorController::class, 'retention']);
    Route::get('/api/endpoints/{id}/forward', [InspectorController::class, 'forwardTarget']);
    Route::put('/api/endpoints/{id}/forward', [InspectorController::class, 'saveForwardTarget'])->middleware('throttle:30,1');
    Route::post('/api/endpoints/{id}/requests/{capture}/replay', [InspectorController::class, 'replay'])->middleware('throttle:20,1');
    Route::post('/api/endpoints/{id}/deliveries/{delivery}/retry', [InspectorController::class, 'retryDelivery'])->middleware('throttle:20,1');
    Route::get('/api/endpoints/{id}/deliveries', [InspectorController::class, 'deliveries']);
});
