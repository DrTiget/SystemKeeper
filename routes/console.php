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
Schedule::command('backups:rotate')->dailyAt('04:15')->withoutOverlapping();
Schedule::command('metrics:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('metrics:prune')->dailyAt('03:45');
Schedule::command('alerts:prune')->dailyAt('04:00');
