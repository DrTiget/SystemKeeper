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

    // Загрузка «по ссылке»: Диск сам скачивает файл с SystemKeeper (или из S3). false — только прямая загрузка
    'fetch_by_url' => (bool) env('YANDEX_DISK_FETCH_BY_URL', true),

    // Файлы меньше, байт, загружаются напрямую — у скачивания по ссылке постоянная задержка около 15 секунд
    'fetch_min_size' => (int) env('YANDEX_DISK_FETCH_MIN_SIZE', 8 * 1024 * 1024),

    // Сколько файлов Диск скачивает по ссылке одновременно — по умолчанию; у хранилища своя настройка (1–10)
    'parallel' => (int) env('YANDEX_DISK_PARALLEL', 4),

    // Как часто спрашивать о ходе скачивания по ссылке, мс
    'poll_ms' => (int) env('YANDEX_DISK_POLL_MS', 3000),
];
