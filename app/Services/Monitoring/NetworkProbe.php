<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;

/** Низкоуровневые сетевые проверки; в тестах подменяется фейком. Ошибки — MonitorProbeException. */
interface NetworkProbe
{
    /** Открывает TCP-соединение и сразу закрывает его. */
    public function tcp(string $host, int $port, int $timeout): void;

    /** Один ICMP echo. */
    public function ping(string $host, int $timeout): void;

    /** Дата окончания TLS-сертификата хоста (сертификат должен быть валиден). */
    public function sslExpiresAt(string $host, int $port, int $timeout): CarbonImmutable;
}
