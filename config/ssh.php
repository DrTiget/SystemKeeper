<?php

return [
    // Таймаут установки SSH-соединения, секунд
    'connect_timeout' => (int) env('SSH_CONNECT_TIMEOUT', 10),

    // Таймаут выполнения команды, секунд
    'exec_timeout' => (int) env('SSH_EXEC_TIMEOUT', 30),

    // Драйвер потоковых операций (бекапы): openssh — системный клиент ssh (быстро, ~сотни МБ/с),
    // phpseclib — чистый PHP (медленно, ~5 МБ/с, ничего не требует от окружения)
    'stream_driver' => env('SSH_STREAM_DRIVER', 'openssh'),

    // Бинарник OpenSSH-клиента для драйвера openssh
    'openssh_binary' => env('SSH_BINARY', 'ssh'),

    // Команда проверки подключения
    'check_command' => 'uname -a && uptime',
];
