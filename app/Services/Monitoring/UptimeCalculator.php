<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;
use App\Models\MonitorCheck;

/** Uptime = доля успешных проверок за окно. */
class UptimeCalculator
{
    public const WINDOWS = ['24h' => 1, '7d' => 7, '30d' => 30];

    /**
     * @return array<string, array{percent: float|null, checks: int, failed: int}>
     */
    public function forMonitor(Monitor $monitor): array
    {
        $result = [];
        foreach (self::WINDOWS as $label => $days) {
            $row = MonitorCheck::query()
                ->where('monitor_id', $monitor->id)
                ->where('checked_at', '>=', now()->subDays($days))
                ->selectRaw('count(*) as total, coalesce(sum(status = ?), 0) as failed', [Monitor::STATUS_DOWN])
                ->first();

            $total = (int) $row->total;
            $failed = (int) $row->failed;
            $result[$label] = [
                'percent' => $total === 0 ? null : round(($total - $failed) / $total * 100, 3),
                'checks' => $total,
                'failed' => $failed,
            ];
        }

        return $result;
    }
}
