<?php

return [
    // Таймаут установки SSH-соединения, секунд
    'connect_timeout' => (int) env('SSH_CONNECT_TIMEOUT', 10),

    // Таймаут выполнения команды, секунд
    'exec_timeout' => (int) env('SSH_EXEC_TIMEOUT', 30),

    // Команда проверки подключения
    'check_command' => 'uname -a && uptime',
];
