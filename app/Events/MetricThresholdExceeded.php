<?php

namespace App\Events;

use App\Models\Server;
use App\Models\ServerMetric;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Показатель сервера превысил порог (и удержался так нужное число замеров). Для будущих алертов.
 *
 * $metric — disk|load|ram; $target — точка монтирования для disk, иначе null;
 * $value и $threshold — проценты (disk, ram) или load average за 5 минут на ядро (load).
 */
class MetricThresholdExceeded
{
    use Dispatchable;

    public function __construct(
        public readonly Server $server,
        public readonly ServerMetric $sample,
        public readonly string $metric,
        public readonly ?string $target,
        public readonly float $value,
        public readonly float $threshold,
    ) {}
}
