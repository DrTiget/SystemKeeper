<?php

namespace App\Http\Resources;

use App\Models\Server;
use App\Models\ServerMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Показатели сервера и настройки сбора. Оборачивается в ServerMetrics (настройки/снимок) сервера; `spark` передаётся
 * списком через withSpark(), чтобы не делать запрос на каждый сервер.
 *
 * @mixin Server
 */
class ServerMetricsResource extends JsonResource
{
    /** @var array{cpu: list<float>, memory: list<float>} */
    private array $spark = ['cpu' => [], 'memory' => []];

    /** @param array{cpu: list<float>, memory: list<float>} $spark */
    public function withSpark(array $spark): static
    {
        $this->spark = $spark;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metrics = $this->metricsOrDefault();
        $snapshot = $metrics->snapshot ?? [];

        return [
            'server' => [
                'id' => $this->id,
                'name' => $this->name,
                'host' => $this->host,
                'project' => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null,
            ],
            /** ok — данные свежие; stale — не обновлялись больше трёх интервалов; unreachable — последний сбор не удался; disabled — сбор выключен */
            'status' => $metrics->status(),
            /** Когда сняты показатели; null — ещё не снимались */
            'collected_at' => $metrics->collected_at,
            'error' => $metrics->error,
            'settings' => [
                'enabled' => $metrics->enabled,
                /** Интервал сбора, секунд (30…3600) */
                'interval' => $metrics->interval,
                'retention_days' => $metrics->retention_days,
                'disks_exclude' => $metrics->disks_exclude ?? [],
            ],
            /** Загрузка CPU, % (0…100, по всем ядрам) */
            'cpu' => $snapshot['cpu'] ?? null,
            'cpu_cores' => $snapshot['cpu_cores'] ?? null,
            /** Средняя нагрузка за 1, 5 и 15 минут */
            'load' => $snapshot['load'] ?? null,
            /** Байты */
            'memory' => $snapshot['memory'] ?? null,
            'swap' => $snapshot['swap'] ?? null,
            'disks' => $snapshot['disks'] ?? [],
            /** Байт/с */
            'network' => $snapshot['network'] ?? null,
            /** Время работы сервера, секунд */
            'uptime' => $snapshot['uptime'] ?? null,
            'os' => $snapshot['os'] ?? null,
            /** Последний час: CPU и память в % */
            'spark' => $this->spark,
        ];
    }
}
