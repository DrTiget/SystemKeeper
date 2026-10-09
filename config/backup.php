<?php

return [
    // Диск Laravel для файлов бекапов (config/filesystems.php): storage/app/backups
    'disk' => 'backups',

    // Предельная длительность одного бекапа, секунд (должна быть меньше DB_QUEUE_RETRY_AFTER)
    'timeout' => (int) env('BACKUP_TIMEOUT', 14400),

    // Уровень сжатия zstd по умолчанию (1–19)
    'compression_level' => 3,

    // Сколько символов stderr сохранять в логе бекапа
    'log_limit' => 10000,
];
