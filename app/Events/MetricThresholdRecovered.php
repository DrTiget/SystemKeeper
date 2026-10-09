<?php

namespace App\Events;

use App\Models\Server;
use App\Models\ServerMetric;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Показатель вернулся в норму после MetricThresholdExceeded. Поля — как у MetricThresholdExceeded;
 * $value — последнее значение (уже в норме), $exceededSince — когда зафиксировано превышение.
 */
class MetricThresholdRecovered
{
    use Dispatchable;

    public function __construct(
        public readonly Server $server,
        public readonly ServerMetric $sample,
        public readonly string $metric,
        public readonly ?string $target,
        public readonly float $value,
        public readonly float $threshold,
        public readonly ?CarbonInterface $exceededSince = null,
    ) {}
}
