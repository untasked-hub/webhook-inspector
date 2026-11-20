<?php

namespace App\Http\Controllers;

use App\Support\Health;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Public readiness probe. Returns 503 only when the database cannot be reached,
     * so load balancers stop routing traffic; a stale worker or scheduler is reported
     * as "degraded" with HTTP 200 and a coarse state per process.
     */
    public function __invoke(): JsonResponse
    {
        $report = Health::report();

        return response()->json($report, $report['status'] === 'unavailable' ? 503 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
