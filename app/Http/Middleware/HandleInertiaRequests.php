<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function handle(Request $request, \Closure $next)
    {
        $response = parent::handle($request, $next);

        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Vary', trim(implode(', ', array_unique(array_filter(array_merge(
                array_map('trim', explode(',', (string) $response->headers->get('Vary', ''))),
                ['Cookie', 'X-Inertia']
            ))))));
        }

        return $response;
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? ['name' => $user->name] : null,
            ],
        ];
    }
}
