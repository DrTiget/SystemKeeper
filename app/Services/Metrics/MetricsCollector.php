<?php

namespace App\Services\Metrics;

use App\Models\Server;
use App\Models\ServerMetric;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MetricsCollector
{
    /** Одна SSH-команда на все показатели; разделы помечены строками @@SK:<имя>. */
    public const COMMAND = "export LC_ALL=C; echo '@@SK:loadavg'; cat /proc/loadavg; echo '@@SK:meminfo'; cat /proc/meminfo; "
        ."echo '@@SK:nproc'; (nproc 2>/dev/null || grep -c '^processor' /proc/cpuinfo); echo '@@SK:uptime'; cat /proc/uptime; "
        ."echo '@@SK:df'; (df -PTk 2>/dev/null || df -Pk); echo '@@SK:dfi'; df -Pi 2>/dev/null; true";

    public function __construct(
        private readonly SshConnectorInterface $ssh,
        private readonly MetricsParser $parser,
        private readonly ThresholdEvaluator $evaluator,
    ) {}

    /**
     * Собирает метрики сервера, сохраняет замер и проверяет пороги.
     * Результат (успех или текст ошибки) пишется в metrics_collected_at / metrics_last_error сервера.
     *
     * @throws Throwable ошибка подключения, команды или разбора
     */
    public function collect(Server $server): ServerMetric
    {
        try {
            $result = $this->ssh->exec($server, self::COMMAND, (int) config('metrics.exec_timeout'));
            if ($result['stdout'] === '' && ($result['exit_code'] ?? 0) !== 0) {
                throw new RuntimeException('Команда сбора метрик завершилась с кодом '.$result['exit_code'].': '.trim($result['stderr']));
            }
            $data = $this->parser->parse($result['stdout']);
        } catch (Throwable $e) {
            $server->forceFill(['metrics_last_error' => Str::limit($e->getMessage(), 1000)])->save();

            throw $e;
        }

        $metric = $server->metrics()->create($data + ['collected_at' => now()]);
        $server->forceFill(['metrics_collected_at' => $metric->collected_at, 'metrics_last_error' => null])->save();

        $this->evaluator->evaluate($server, $metric);

        return $metric;
    }
}
