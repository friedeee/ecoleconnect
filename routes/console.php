<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly backup of the database and uploaded files (needs the scheduler: `php artisan schedule:run` every minute).
Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping();
