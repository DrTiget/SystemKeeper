<?php

return [
    'base_url' => env('YANDEX_DISK_BASE_URL', 'https://cloud-api.yandex.net/v1/disk'),

    // Число попыток на запрос при 429/5xx и сетевых сбоях.
    'attempts' => (int) env('YANDEX_DISK_ATTEMPTS', 5),

    // Базовая задержка backoff, мс: base * 2^(попытка-1).
    'backoff_ms' => (int) env('YANDEX_DISK_BACKOFF_MS', 1000),

    // Таймаут обычных запросов и самой загрузки файла, сек.
    'timeout' => (int) env('YANDEX_DISK_TIMEOUT', 30),
    'upload_timeout' => (int) env('YANDEX_DISK_UPLOAD_TIMEOUT', 3600),

    // Сколько раз опрашивать md5 ресурса после загрузки (Диск считает его не мгновенно).
    'verify_attempts' => (int) env('YANDEX_DISK_VERIFY_ATTEMPTS', 5),
];
