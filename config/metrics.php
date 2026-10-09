<?php

return [
    // Сколько дней хранить метрики серверов
    'retention_days' => (int) env('METRICS_RETENTION_DAYS', 30),

    // Максимум серверов, ставимых в очередь за один запуск планировщика
    'dispatch_limit' => (int) env('METRICS_DISPATCH_LIMIT', 200),

    // Таймаут SSH-команды сбора, секунд
    'exec_timeout' => (int) env('METRICS_EXEC_TIMEOUT', 30),
];
