<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;

/**
 * Выполняет проверку монитора. В тестах подменяется (сети там нет).
 */
interface MonitorChecker
{
    /** Не бросает исключений: любая ошибка — `CheckResult::down()`. */
    public function check(Monitor $monitor): CheckResult;
}
