<?php

return [
    // Диск Laravel для файлов бекапов (config/filesystems.php): storage/app/backups
    'disk' => 'backups',

    // Предельная длительность одного бекапа, секунд (должна быть меньше DB_QUEUE_RETRY_AFTER)
    'timeout' => (int) env('BACKUP_TIMEOUT', 14400),

    // Уровень сжатия zstd по умолчанию (1–19)
    'compression_level' => 3,

    // Срок жизни временной ссылки на скачивание бекапа, минут
    'download_link_minutes' => (int) env('BACKUP_DOWNLOAD_LINK_MINUTES', 5),

    // Через сколько часов «ожидающая» или «выгружаемая» копия в хранилище считается потерянной
    'upload_stale_hours' => 24,

    // Через сколько суток ротация удаляет записи о неуспешных бекапах (если у задания есть правила ротации)
    'failed_retention_days' => 7,

    // Тестовое восстановление дампов (verify_restore). Нужна учётная запись, которой разрешено создавать и удалять
    // базы sk_restore_*: GRANT ALL ON `sk\_restore\_%`.* TO 'user'@'%'. По умолчанию — MySQL проекта (DB_*).
    'restore' => [
        'timeout' => (int) env('BACKUP_RESTORE_TIMEOUT', 3600),
        'mysql' => [
            'host' => env('BACKUP_RESTORE_MYSQL_HOST', env('DB_HOST', 'mysql')),
            'port' => (int) env('BACKUP_RESTORE_MYSQL_PORT', env('DB_PORT', 3306)),
            'username' => env('BACKUP_RESTORE_MYSQL_USERNAME', env('DB_USERNAME')),
            'password' => env('BACKUP_RESTORE_MYSQL_PASSWORD', env('DB_PASSWORD')),
            // Клиент в контейнере проверяет TLS-сертификат; у MySQL проекта он самоподписанный
            'ssl_verify' => (bool) env('BACKUP_RESTORE_MYSQL_SSL_VERIFY', false),
        ],
    ],

    // Сколько символов stderr сохранять в логе бекапа
    'log_limit' => 10000,
];
