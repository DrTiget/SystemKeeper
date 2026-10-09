<?php

namespace App\Services\Monitoring;

use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Events\SslExpiring;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Сохраняет результат проверки, ведёт счётчик неудач (антидребезг) и открывает/закрывает инциденты. */
class MonitorRecorder
{
    public function record(Monitor $monitor, CheckResult $result): MonitorCheck
    {
        $events = [];

        $check = DB::transaction(function () use ($monitor, $result, &$events) {
            $monitor->newQuery()->whereKey($monitor->getKey())->lockForUpdate()->first();
            $monitor->refresh();

            $now = now();
            $check = $monitor->checks()->create([
                'status' => $result->up ? Monitor::STATUS_UP : Monitor::STATUS_DOWN,
                'response_time_ms' => $result->responseTimeMs,
                'status_code' => $result->statusCode,
                'error' => $result->error === null ? null : Str::limit($result->error, 1000),
                'checked_at' => $now,
            ]);

            $monitor->forceFill([
                'last_checked_at' => $now,
                'last_status' => $check->status,
                'last_response_time_ms' => $check->response_time_ms,
                'last_error' => $check->error,
                'consecutive_failures' => $result->up ? 0 : $monitor->consecutive_failures + 1,
            ])->save();

            $open = $monitor->openIncident()->first();

            if ($result->up && $open) {
                $open->update(['resolved_at' => $now, 'duration_seconds' => max(0, (int) $open->started_at->diffInSeconds($now, true))]);
                $events[] = new IncidentResolved($open);
            } elseif (! $result->up && ! $open && $monitor->consecutive_failures >= max(1, $monitor->failure_threshold)) {
                $incident = $monitor->incidents()->create(['started_at' => $now, 'reason' => $check->error]);
                $events[] = new IncidentOpened($incident);
            }

            if (! $result->up && $result->sslDaysLeft !== null) {
                $events[] = new SslExpiring($monitor, $result->sslDaysLeft);
            }

            return $check;
        });

        foreach ($events as $event) {
            event($event);
        }

        return $check;
    }

    /** Принят пинг heartbeat: монитор жив, открытый инцидент закрывается. */
    public function heartbeat(Monitor $monitor): void
    {
        $monitor->forceFill(['last_heartbeat_at' => now()])->save();

        if ($monitor->last_status === Monitor::STATUS_DOWN || $monitor->openIncident()->exists()) {
            $this->record($monitor, CheckResult::up());
        }
    }
}
