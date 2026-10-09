<?php

namespace Tests\Support;

use App\Services\Monitoring\MonitorProbeException;
use App\Services\Monitoring\NetworkProbe;
use Carbon\CarbonImmutable;

class FakeNetworkProbe implements NetworkProbe
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        public bool $reachable = true,
        public ?CarbonImmutable $sslExpiresAt = null,
    ) {}

    public function tcp(string $host, int $port, int $timeout): void
    {
        $this->calls[] = "tcp:{$host}:{$port}";
        $this->guard();
    }

    public function ping(string $host, int $timeout): void
    {
        $this->calls[] = "ping:{$host}";
        $this->guard();
    }

    public function sslExpiresAt(string $host, int $port, int $timeout): CarbonImmutable
    {
        $this->calls[] = "ssl:{$host}:{$port}";
        $this->guard();

        return $this->sslExpiresAt ?? CarbonImmutable::now()->addDays(90);
    }

    private function guard(): void
    {
        if (! $this->reachable) {
            throw new MonitorProbeException('Соединение отклонено');
        }
    }
}
