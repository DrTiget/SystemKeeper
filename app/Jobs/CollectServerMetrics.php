<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Metrics\MetricsCollector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Снимает ресурсы одного сервера. Не накладывается на сбор того же сервера.
 */
class CollectServerMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $serverId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("server-metrics:{$this->serverId}"))->dontRelease()->expireAfter(120)];
    }

    public function handle(MetricsCollector $collector): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server) {
            $collector->collect($server);
        }
    }
}
