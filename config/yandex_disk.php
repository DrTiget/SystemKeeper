<?php

return [
    'base_url' => env('YANDEX_DISK_BASE_URL', 'https://cloud-api.yandex.net/v1/disk'),

    // Число попыток на запрос при 429/5xx и сетевых сбоях
    'attempts' => (int) env('YANDEX_DISK_ATTEMPTS', 5),

    // Базовая задержка между попытками, мс: base * 2^(попытка-1)
    'backoff_ms' => (int) env('YANDEX_DISK_BACKOFF_MS', 1000),

    // Таймаут обычных запросов и передачи файла, сек.
    'timeout' => (int) env('YANDEX_DISK_TIMEOUT', 30),
    'transfer_timeout' => (int) env('YANDEX_DISK_TRANSFER_TIMEOUT', 14400),
];
