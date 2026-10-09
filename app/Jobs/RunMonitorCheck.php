<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\Monitoring\MonitorChecker;
use App\Services\Monitoring\MonitorRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunMonitorCheck implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $monitorId) {}

    public function handle(MonitorChecker $checker, MonitorRecorder $recorder): void
    {
        $monitor = Monitor::find($this->monitorId);
        if ($monitor === null || ! $monitor->enabled) {
            return;
        }

        $result = $checker->check($monitor);
        $recorder->record($monitor, $result);

        if ($monitor->isHeartbeat()) {
            // Следующая проверка — когда истечёт окно от последнего пинга, но не чаще раза в минуту
            $reference = $monitor->last_heartbeat_at ?? $monitor->enabled_at ?? $monitor->created_at;
            $next = $reference->copy()->addSeconds($monitor->interval_seconds + $monitor->heartbeat_grace_seconds + 1);
            $monitor->forceFill(['next_check_at' => $result->up ? max($next, now()->addMinute()) : now()->addMinute()])->save();
        }
    }
}
