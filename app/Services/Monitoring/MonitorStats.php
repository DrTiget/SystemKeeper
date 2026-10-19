<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Статистика мониторов для API: uptime за 24 часа / 7 / 30 дней, среднее время ответа за 24 часа и последние проверки —
 * двумя запросами на весь список.
 */
class MonitorStats
{
    public const RECENT = 50;

    /**
     * Кладёт в `$monitor->stats` значения для ответа API.
     *
     * @param  Collection<int, Monitor>  $monitors
     */
    public function load(Collection $monitors): void
    {
        if ($monitors->isEmpty()) {
            return;
        }
        $ids = $monitors->pluck('id')->all();
        $now = now();
        $h24 = $now->clone()->subDay();
        $d7 = $now->clone()->subDays(7);
        $d30 = $now->clone()->subDays(30);

        $sum = fn (string $status, $from) => "SUM(status = '{$status}' AND created_at >= '{$from->toDateTimeString()}')";
        $rows = MonitorHeartbeat::query()
            ->whereIn('monitor_id', $ids)
            ->where('created_at', '>=', $d30)
            ->groupBy('monitor_id')
            ->select('monitor_id')
            ->selectRaw($sum('up', $h24).' as up24, '.$sum('down', $h24).' as down24')
            ->selectRaw($sum('up', $d7).' as up7, '.$sum('down', $d7).' as down7')
            ->selectRaw("SUM(status = 'up') as up30, SUM(status = 'down') as down30")
            ->selectRaw("AVG(CASE WHEN created_at >= '{$h24->toDateTimeString()}' THEN response_ms END) as avg24")
            ->get()->keyBy('monitor_id');

        $recent = DB::query()->fromSub(
            MonitorHeartbeat::query()->whereIn('monitor_id', $ids)
                ->select('*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY monitor_id ORDER BY created_at DESC, id DESC) as rn'),
            'h',
        )->where('rn', '<=', self::RECENT)->orderBy('created_at')->orderBy('id')->get()->groupBy('monitor_id');

        foreach ($monitors as $monitor) {
            $r = $rows[$monitor->id] ?? null;
            $monitor->stats = [
                'uptime' => [
                    'h24' => $r ? MonitorStatus::uptime((int) $r->up24, (int) $r->down24) : null,
                    'd7' => $r ? MonitorStatus::uptime((int) $r->up7, (int) $r->down7) : null,
                    'd30' => $r ? MonitorStatus::uptime((int) $r->up30, (int) $r->down30) : null,
                ],
                'avg_response_ms' => $r && $r->avg24 !== null ? (int) round($r->avg24) : null,
                'recent' => ($recent[$monitor->id] ?? collect())->map(fn ($h) => [
                    'at' => Carbon::parse($h->created_at, 'UTC'),
                    'status' => $h->status,
                    'response_ms' => $h->response_ms,
                    'status_code' => $h->status_code,
                    'message' => $h->message,
                ])->values()->all(),
            ];
        }
    }
}
