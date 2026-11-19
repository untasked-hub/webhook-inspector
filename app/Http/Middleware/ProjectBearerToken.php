<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ProjectAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ProjectBearerToken
{
    public function handle(Request $request, Closure $next)
    {
        // Always JSON, regardless of caller Accept; this stack never starts a browser session.
        $request->headers->set('Accept', 'application/json');
        $raw = $request->bearerToken();
        abort_unless(is_string($raw) && preg_match('/^wi_[A-Za-z0-9]{64}$/D', $raw), 401, 'Bearer API token required.');
        $token = DB::table('project_api_tokens')->where('token_hash', hash('sha256', $raw))->first();
        abort_unless($token, 401, 'Invalid or revoked API token.');
        $user = User::find($token->user_id);
        abort_unless($user, 401);
        $request->setUserResolver(fn () => $user);
        ProjectAccess::authorize($request, $token->project_id, !$request->isMethod('GET'));
        abort_if(!$request->isMethod('GET') && $token->scope !== 'read+replay', 403, 'Token requires read+replay scope.');
        // Shared project budget: issuing more tokens cannot multiply the quota.
        $key = 'project-api:'.$token->project_id;
        abort_if(RateLimiter::tooManyAttempts($key, 120), 429, 'Project API limit: 120 requests per minute.');
        RateLimiter::hit($key, 60);
        $request->attributes->set('api_project', $token->project_id);
        DB::table('project_api_tokens')->where('id', $token->id)->update(['last_used_at' => now()]);
        return $next($request)->header('Cache-Control', 'no-store');
    }
}
