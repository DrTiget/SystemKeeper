<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('monitors:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('monitors:prune')->dailyAt('03:30');
Schedule::command('backups:run-due')->everyMinute()->withoutOverlapping();
