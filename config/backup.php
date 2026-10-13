<?php

return [
    // Часовой пояс, в котором понимается расписание бекапов (cron)
    'timezone' => env('BACKUP_TIMEZONE', 'Europe/Moscow'),

    // Расписание по умолчанию — каждый день в 03:00
    'default_schedule' => '0 3 * * *',

    // Таймаут команды перед бекапом по умолчанию, секунд
    'pre_command_timeout' => 300,
];
