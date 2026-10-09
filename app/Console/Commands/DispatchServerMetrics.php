<?php

namespace App\Console\Commands;

use App\Jobs\CollectServerMetrics;
use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metrics:dispatch')]
#[Description('Ставит в очередь сбор метрик серверов, срок которых подошёл')]
class DispatchServerMetrics extends Command
{
    public function handle(): int
    {
        $count = 0;

        Server::query()->metricsDue()->orderBy('metrics_next_at')->limit((int) config('metrics.dispatch_limit'))->get()
            ->each(function (Server $server) use (&$count) {
                // Срок сдвигаем заранее, чтобы следующий запуск планировщика не поставил сбор повторно
                $server->forceFill(['metrics_next_at' => now()->addMinutes(max($server->metrics_interval_minutes, 1))])->save();
                CollectServerMetrics::dispatch($server->id);
                $count++;
            });

        $this->info("В очередь поставлено сборов метрик: {$count}");

        return self::SUCCESS;
    }
}
