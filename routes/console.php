<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\SetupWorkerMandatesJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SetupWorkerMandatesJob())
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('setup-worker-mandates');

Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=120')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('shared-hosting-queue-worker')
    ->before(function () {
        Log::info('[Schedule] Queue worker started at ' . now());
    })
    ->after(function () {
        Log::info('[Schedule] Queue worker finished at ' . now());
    });
