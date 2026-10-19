<?php

namespace Tests\Support;

use App\Models\Monitor;
use App\Services\Monitoring\CheckResult;
use App\Services\Monitoring\MonitorChecker;

/**
 * Проверки по очереди готовых результатов; последний повторяется. Запоминает проверенные мониторы.
 */
class FakeMonitorChecker implements MonitorChecker
{
    /** @var list<Monitor> */
    public array $checked = [];

    /** @param  list<CheckResult>  $results */
    public function __construct(private array $results = [])
    {
        $this->results = $results ?: [CheckResult::up(120, '200 OK', 200)];
    }

    public function check(Monitor $monitor): CheckResult
    {
        $this->checked[] = $monitor;

        return count($this->results) > 1 ? array_shift($this->results) : $this->results[0];
    }
}
