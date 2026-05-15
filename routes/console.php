<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\SetupWorkerMandatesJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SetupWorkerMandatesJob())
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('setup-worker-mandates');
