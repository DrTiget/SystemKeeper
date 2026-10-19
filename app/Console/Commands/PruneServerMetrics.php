<?php

namespace App\Console\Commands;

use App\Models\ServerMetricPoint;
use App\Models\ServerMetrics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metrics:prune')]
#[Description('Удалить историю ресурсов серверов старше срока хранения, заданного у каждого сервера (планировщик, раз в сутки)')]
class PruneServerMetrics extends Command
{
    public function handle(): int
    {
        $deleted = 0;
        foreach (ServerMetrics::query()->get(['id', 'server_id', 'retention_days']) as $metrics) {
            $before = now()->subDays($metrics->retention_days);
            // порциями, чтобы не держать блокировку на большой таблице
            while (($n = ServerMetricPoint::query()->where('server_id', $metrics->server_id)->where('created_at', '<', $before)->limit(10000)->delete()) > 0) {
                $deleted += $n;
            }
        }

        $this->line("Удалено точек: {$deleted}");

        return self::SUCCESS;
    }
}
