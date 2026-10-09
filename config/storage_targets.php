<?php

return [
    's3' => [
        // Размер части multipart-загрузки, байт (минимум у S3 — 5 МиБ). Для огромных файлов увеличивается автоматически (лимит 10 000 частей).
        'part_size' => (int) env('S3_PART_SIZE', 16 * 1024 * 1024),

        // Файлы не больше порога уходят одним PutObject, большие — multipart
        'multipart_threshold' => (int) env('S3_MULTIPART_THRESHOLD', 16 * 1024 * 1024),

        // Попытки SDK на запрос при 5xx/throttling (экспоненциальный backoff внутри SDK)
        'attempts' => (int) env('S3_ATTEMPTS', 5),

        'connect_timeout' => (int) env('S3_CONNECT_TIMEOUT', 10),

        // Таймаут на одну часть/запрос, сек.
        'timeout' => (int) env('S3_TIMEOUT', 600),
    ],

    'sftp' => [
        // Таймаут загрузки файла на другой сервер через ssh, сек.
        'upload_timeout' => (int) env('SFTP_UPLOAD_TIMEOUT', 7200),

        // Таймаут служебных команд (sha256sum, mkdir, rm, df), сек.
        'command_timeout' => (int) env('SFTP_COMMAND_TIMEOUT', 600),

        // Попытки загрузки при сбое соединения
        'attempts' => (int) env('SFTP_ATTEMPTS', 3),
        'backoff_ms' => (int) env('SFTP_BACKOFF_MS', 2000),
    ],
];
