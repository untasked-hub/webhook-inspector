<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('Welcome', [
            'title' => config('app.name', 'Webhook Inspector'),
        ]);
    }
}
