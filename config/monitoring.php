<?php

return [
    // Сколько дней хранить результаты проверок (uptime считается максимум за 30 дней)
    'check_retention_days' => (int) env('MONITOR_CHECK_RETENTION_DAYS', 35),

    // Максимум мониторов, ставимых в очередь за один запуск планировщика
    'dispatch_limit' => (int) env('MONITOR_DISPATCH_LIMIT', 500),
];
