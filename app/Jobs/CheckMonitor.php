<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\Monitoring\MonitorChecker;
use App\Services\Monitoring\MonitorRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Проверяет один монитор и записывает результат.
 */
class CheckMonitor implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public Monitor $monitor) {}

    public function handle(MonitorChecker $checker, MonitorRecorder $recorder): void
    {
        $monitor = $this->monitor->fresh();
        if (! $monitor || ! $monitor->enabled) {
            return;
        }

        $recorder->record($monitor, $checker->check($monitor));
    }
}
