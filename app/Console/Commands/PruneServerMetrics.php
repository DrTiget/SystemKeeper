<?php

namespace App\Console\Commands;

use App\Models\ServerMetric;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metrics:prune {--days= : Хранить метрики за N дней (по умолчанию metrics.retention_days)}')]
#[Description('Удаляет старые метрики серверов')]
class PruneServerMetrics extends Command
{
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('metrics.retention_days'));
        $deleted = 0;
        $query = ServerMetric::query()->where('collected_at', '<', now()->subDays($days));

        // Пачками, чтобы не держать долгую блокировку на большой таблице
        do {
            $batch = (clone $query)->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Удалено замеров метрик: {$deleted}");

        return self::SUCCESS;
    }
}
