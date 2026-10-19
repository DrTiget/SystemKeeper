<?php

namespace App\Services\Metrics;

/**
 * Shell-скрипт сбора ресурсов Linux-сервера одним SSH-вызовом. Только чтение /proc, `df` и /etc/os-release.
 * Счётчики CPU и сети снимаются дважды с паузой в секунду — отсюда загрузка и скорость. Разбирает вывод MetricsParser.
 */
class MetricsScript
{
    private const SCRIPT = <<<'SH'
# systemkeeper-metrics
export LC_ALL=C
echo '#uptime1'; cat /proc/uptime
echo '#stat1'; grep '^cpu ' /proc/stat
echo '#net1'; cat /proc/net/dev
sleep 1
echo '#uptime2'; cat /proc/uptime
echo '#stat2'; grep '^cpu ' /proc/stat
echo '#net2'; cat /proc/net/dev
echo '#loadavg'; cat /proc/loadavg
echo '#meminfo'; grep -E '^(MemTotal|MemFree|MemAvailable|Buffers|Cached|SReclaimable|SwapTotal|SwapFree):' /proc/meminfo
echo '#os'; grep -E '^(PRETTY_NAME|NAME|VERSION)=' /etc/os-release 2>/dev/null
echo '#nproc'; nproc 2>/dev/null || grep -c '^processor' /proc/cpuinfo
echo '#df'; df -PTB1 2>/dev/null || df -PB1 2>/dev/null
echo '#end'
SH;

    /** Команда для SshConnectorInterface::exec */
    public static function command(): string
    {
        return 'sh -c '.escapeshellarg(self::SCRIPT);
    }
}
