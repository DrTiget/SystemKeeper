<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('system:check-updates')->everyTenMinutes()->withoutOverlapping()->runInBackground();
Schedule::command('backups:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('monitors:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('monitors:prune')->dailyAt('03:30');
Schedule::command('metrics:dispatch-due')->everyMinute()->withoutOverlapping();
Schedule::command('metrics:prune')->dailyAt('03:45');
