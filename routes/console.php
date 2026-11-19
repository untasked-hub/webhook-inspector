<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('captures:purge', function () {
    $count = DB::table('captures')->where('expires_at', '<=', now())->delete();
    $this->info("Purged {$count} expired captures.");
})->purpose('Permanently delete expired captured payloads and dependent delivery records');

Schedule::command('captures:purge')->everyMinute()->withoutOverlapping();
