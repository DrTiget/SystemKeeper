<?php

namespace App\Services\Metrics;

use App\Models\Server;
use App\Models\ServerMetric;
use Carbon\CarbonInterface;

/** История метрик для графиков: замеры за период усредняются по равным окнам (бакетам). */
class MetricsHistory
{
    /**
     * @return array{from: string, to: string, bucket_seconds: int, points: list<array<string, mixed>>}
     */
    public function aggregate(Server $server, CarbonInterface $from, CarbonInterface $to, int $maxPoints): array
    {
        $range = max($to->getTimestamp() - $from->getTimestamp(), 1);
        // Окно не мельче минуты: замеры реже
        $bucket = max((int) ceil($range / $maxPoints), 60);

        /** @var array<int, array{n: int, rows: list<ServerMetric>}> $buckets */
        $buckets = [];
        $server->metrics()
            ->whereBetween('collected_at', [$from, $to])
            ->orderBy('collected_at')
            ->lazyById(2000)
            ->each(function (ServerMetric $m) use (&$buckets, $bucket) {
                $key = intdiv($m->collected_at->getTimestamp(), $bucket) * $bucket;
                $buckets[$key][] = $m;
            });
        ksort($buckets);

        $points = [];
        foreach ($buckets as $start => $rows) {
            $points[] = $this->point($start, $rows);
        }

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'bucket_seconds' => $bucket,
            'points' => $points,
        ];
    }

    /** @param  list<ServerMetric>  $rows */
    private function point(int $start, array $rows): array
    {
        $avg = fn (callable $f) => round(array_sum(array_map($f, $rows)) / count($rows), 2);
        $max = fn (callable $f) => max(array_map($f, $rows));

        $disks = [];
        foreach ($rows as $row) {
            foreach ($row->disks as $disk) {
                $disks[$disk['mount']][] = $disk;
            }
        }
        ksort($disks);

        return [
            'time' => date('c', $start),
            'samples' => count($rows),
            'load1' => ['avg' => $avg(fn ($m) => $m->load1), 'max' => $max(fn ($m) => $m->load1)],
            'load5' => ['avg' => $avg(fn ($m) => $m->load5), 'max' => $max(fn ($m) => $m->load5)],
            'load15' => ['avg' => $avg(fn ($m) => $m->load15), 'max' => $max(fn ($m) => $m->load15)],
            'load_per_core' => ['avg' => $avg(fn ($m) => $m->loadPerCore()), 'max' => $max(fn ($m) => $m->loadPerCore())],
            'mem_used_percent' => ['avg' => $avg(fn ($m) => $m->memUsedPercent()), 'max' => $max(fn ($m) => $m->memUsedPercent())],
            'mem_used_bytes' => (int) round(array_sum(array_map(fn ($m) => $m->mem_used, $rows)) / count($rows)),
            'swap_used_percent' => ['avg' => $avg(fn ($m) => $m->swapUsedPercent() ?? 0.0), 'max' => $max(fn ($m) => $m->swapUsedPercent() ?? 0.0)],
            'disks' => array_values(array_map(fn (array $list, string $mount) => [
                'mount' => $mount,
                'total_bytes' => end($list)['total'],
                'used_bytes' => (int) round(array_sum(array_column($list, 'used')) / count($list)),
                'used_percent' => [
                    'avg' => round(array_sum(array_map([ServerMetric::class, 'diskUsedPercent'], $list)) / count($list), 1),
                    'max' => max(array_map([ServerMetric::class, 'diskUsedPercent'], $list)),
                ],
            ], $disks, array_keys($disks))),
        ];
    }
}
