<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Metrics\MetricsCollector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CollectServerMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly int $serverId) {}

    public function handle(MetricsCollector $collector): void
    {
        $server = Server::find($this->serverId);
        if ($server === null || ! $server->metrics_enabled) {
            return;
        }

        try {
            $collector->collect($server);
        } catch (Throwable) {
            // причина сохранена в metrics_last_error; повтор — по расписанию
        }
    }
}
