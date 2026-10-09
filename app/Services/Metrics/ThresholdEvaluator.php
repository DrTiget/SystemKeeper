<?php

namespace App\Services\Metrics;

use App\Events\MetricThresholdExceeded;
use App\Events\MetricThresholdRecovered;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\ServerMetricState;

/**
 * Сверяет замер с порогами сервера и порождает события.
 * Антидребезг: превышение (≥ порога) должно повториться `threshold_confirmations` замеров подряд,
 * и столько же замеров подряд значение должно быть в норме, чтобы сработало событие возврата.
 */
class ThresholdEvaluator
{
    public const METRIC_DISK = 'disk';

    public const METRIC_LOAD = 'load';

    public const METRIC_RAM = 'ram';

    public function evaluate(Server $server, ServerMetric $metric): void
    {
        /** @var array<string, array{value: float, threshold: float|null}> $observed ключ «метрика|цель» */
        $observed = [];

        $observed[self::METRIC_LOAD.'|'] = ['value' => $metric->loadPerCore(), 'threshold' => $server->threshold_load_per_core];
        $observed[self::METRIC_RAM.'|'] = ['value' => $metric->memUsedPercent(), 'threshold' => $server->threshold_ram_percent];
        foreach ($metric->disks as $disk) {
            $observed[self::METRIC_DISK.'|'.$disk['mount']] = [
                'value' => ServerMetric::diskUsedPercent($disk),
                'threshold' => $server->threshold_disk_percent,
            ];
        }

        $states = $server->metricStates()->get()->keyBy(fn (ServerMetricState $s) => $s->metric.'|'.$s->target);
        $confirmations = max($server->threshold_confirmations, 1);

        foreach ($observed as $key => $o) {
            [$name, $target] = explode('|', $key, 2);
            $this->apply($server, $metric, $states->get($key), $name, $target, $o['value'], $o['threshold'], $confirmations);
        }

        // Точка монтирования пропала из замера — считаем, что проблема ушла вместе с ней
        foreach ($states as $key => $state) {
            if (! isset($observed[$key])) {
                $this->forget($server, $metric, $state);
            }
        }
    }

    private function apply(Server $server, ServerMetric $metric, ?ServerMetricState $state, string $name, string $target, float $value, ?float $threshold, int $confirmations): void
    {
        $exceeded = $state?->state === ServerMetricState::STATE_EXCEEDED;
        $breach = $threshold !== null && $value >= $threshold;

        if ($threshold === null) {
            // Порог выключили: открытое превышение закрываем, счётчики сбрасываем
            if ($state) {
                $this->forget($server, $metric, $state);
            }

            return;
        }

        if (! $exceeded && ! $breach && ($state === null || $state->breach_streak === 0)) {
            return; // норма и копить нечего
        }

        $state ??= new ServerMetricState(['server_id' => $server->id, 'metric' => $name, 'target' => $target, 'state' => ServerMetricState::STATE_OK]);
        $state->value = $value;
        $state->threshold = $threshold;

        if (! $exceeded) {
            $state->ok_streak = 0;
            if ($breach) {
                $state->breach_streak++;
                if ($state->breach_streak >= $confirmations) {
                    $state->state = ServerMetricState::STATE_EXCEEDED;
                    $state->exceeded_since = $metric->collected_at;
                    $state->save();
                    MetricThresholdExceeded::dispatch($server, $metric, $name, $target === '' ? null : $target, $value, $threshold);

                    return;
                }
            } else {
                $state->breach_streak = 0; // серия прервалась
            }
            $state->breach_streak === 0 ? $state->delete() : $state->save();

            return;
        }

        // Превышение открыто: ждём возврата в норму
        if ($breach) {
            $state->ok_streak = 0;
            $state->save();

            return;
        }
        $state->ok_streak++;
        if ($state->ok_streak >= $confirmations) {
            $since = $state->exceeded_since;
            $state->delete();
            MetricThresholdRecovered::dispatch($server, $metric, $name, $target === '' ? null : $target, $value, $threshold, $since);

            return;
        }
        $state->save();
    }

    private function forget(Server $server, ServerMetric $metric, ServerMetricState $state): void
    {
        $wasExceeded = $state->state === ServerMetricState::STATE_EXCEEDED;
        $state->delete();
        if ($wasExceeded) {
            MetricThresholdRecovered::dispatch(
                $server, $metric, $state->metric, $state->target === '' ? null : $state->target,
                (float) $state->value, (float) $state->threshold, $state->exceeded_since,
            );
        }
    }
}
