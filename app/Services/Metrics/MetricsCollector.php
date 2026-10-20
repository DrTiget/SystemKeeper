<?php

namespace App\Services\Metrics;

use App\Exceptions\Ssh\SshInternalException;
use App\Models\Server;
use App\Models\ServerMetricPoint;
use App\Models\ServerMetrics;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Снимает ресурсы сервера по SSH и сохраняет результат: последний снимок и точку истории.
 * Неудача (сервер не ответил, не Linux) не бросается наружу, а записывается в `error` — по ней статус «не отвечает».
 */
class MetricsCollector
{
    /** Таймаут команды, секунд: сама она занимает около секунды */
    private const TIMEOUT = 25;

    public function __construct(private SshConnectorInterface $ssh, private MetricsParser $parser) {}

    public function collect(Server $server): ServerMetrics
    {
        $metrics = ServerMetrics::query()->firstOrCreate(['server_id' => $server->id]);
        $now = now();

        try {
            try {
                $result = $this->ssh->exec($server, MetricsScript::command(), self::TIMEOUT);
            } catch (SshInternalException) {
                // единичный сбой библиотеки — одна повторная попытка
                $result = $this->ssh->exec($server, MetricsScript::command(), self::TIMEOUT);
            }
            if ($result['exit_code'] !== 0 && trim($result['stdout']) === '') {
                throw new MetricsException('Команда сбора завершилась с кодом '.($result['exit_code'] ?? '—').': '.trim($result['stderr']));
            }
            $snapshot = $this->parser->parse($result['stdout'], $metrics->disks_exclude ?? []);
        } catch (SshInternalException) {
            // Наш сбой, а не недоступность сервера: данные просто стареют, error не трогаем
            $metrics->forceFill([
                'last_attempt_at' => $now,
                'next_collect_at' => $now->clone()->addSeconds($metrics->interval),
            ])->save();

            return $metrics;
        } catch (Throwable $e) {
            $metrics->forceFill([
                'last_attempt_at' => $now,
                'next_collect_at' => $now->clone()->addSeconds($metrics->interval),
                'error' => Str::limit($e->getMessage(), 1000) ?: 'Не удалось снять показатели',
            ])->save();

            return $metrics;
        }

        $metrics->forceFill([
            'last_attempt_at' => $now,
            'next_collect_at' => $now->clone()->addSeconds($metrics->interval),
            'collected_at' => $now,
            'snapshot' => $snapshot,
            'error' => null,
        ])->save();

        ServerMetricPoint::create([
            'server_id' => $server->id,
            'created_at' => $now,
            'cpu' => $snapshot['cpu'],
            'memory' => $snapshot['memory'] ? $this->percent($snapshot['memory']) : null,
            'swap' => $snapshot['swap'] && $snapshot['swap']['total'] ? $this->percent($snapshot['swap']) : null,
            'load1' => $snapshot['load'][0] ?? null,
            'load5' => $snapshot['load'][1] ?? null,
            'load15' => $snapshot['load'][2] ?? null,
            'rx' => $snapshot['network']['rx'] ?? null,
            'tx' => $snapshot['network']['tx'] ?? null,
            'disks' => MetricsParser::diskPercents($snapshot['disks']),
        ]);

        return $metrics;
    }

    /** @param array{used: int, total: int} $usage */
    private function percent(array $usage): float
    {
        return $usage['total'] > 0 ? round($usage['used'] / $usage['total'] * 100, 2) : 0.0;
    }
}
