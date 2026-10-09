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
        return $this->forMonitors([$monitor->id])[$monitor->id];
    }

    /**
     * Uptime сразу для набора мониторов — один запрос на все окна.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, array{percent: float|null, checks: int, failed: int}>>
     */
    public function forMonitors(array $ids): array
    {
        $select = ['monitor_id'];
        $bindings = [];
        foreach (self::WINDOWS as $label => $days) {
            $since = now()->subDays($days);
            $select[] = "sum(checked_at >= ?) as total_{$days}";
            $select[] = "coalesce(sum(checked_at >= ? and status = ?), 0) as failed_{$days}";
            array_push($bindings, $since, $since, Monitor::STATUS_DOWN);
        }

        $rows = $ids === [] ? collect() : MonitorCheck::query()
            ->whereIn('monitor_id', $ids)
            ->where('checked_at', '>=', now()->subDays(max(self::WINDOWS)))
            ->groupBy('monitor_id')
            ->selectRaw(implode(', ', $select), $bindings)
            ->get()
            ->keyBy('monitor_id');

        $result = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            foreach (self::WINDOWS as $label => $days) {
                $total = (int) ($row?->{"total_{$days}"} ?? 0);
                $failed = (int) ($row?->{"failed_{$days}"} ?? 0);
                $result[$id][$label] = [
                    'percent' => $total === 0 ? null : round(($total - $failed) / $total * 100, 3),
                    'checks' => $total,
                    'failed' => $failed,
                ];
            }
        }

        return $result;
    }
}
