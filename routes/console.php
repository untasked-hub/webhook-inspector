<?php

use App\Support\Billing;
use App\Support\Health;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('captures:purge', function () {
    $count = DB::table('captures')->where('expires_at', '<=', now())->delete();
    $this->info("Purged {$count} expired captures.");
})->purpose('Permanently delete expired captured payloads and dependent delivery records');

Artisan::command('billing:preflight', function () {
    $checks = Billing::configurationChecks();
    foreach ($checks as $name => $passed) {
        $this->line(($passed ? 'PASS ' : 'FAIL ').$name);
    }
    $this->line('Local configuration only. Provider access, price details, webhook delivery, and portal are not verified.');

    return in_array(false, $checks, true) ? 1 : 0;
})->purpose('Check billing configuration without calling Stripe or printing credentials');

Schedule::command('captures:purge')->everyMinute()->withoutOverlapping();

// Liveness heartbeats read by /api/health. Written from the worker loop and the scheduler tick.
Queue::looping(fn () => Health::heartbeat(Health::WORKER_KEY));
Schedule::call(fn () => Health::heartbeat(Health::SCHEDULER_KEY))->everyMinute();
