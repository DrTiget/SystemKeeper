<?php

return [
    // Часовой пояс, в котором понимается расписание бекапов (cron)
    'timezone' => env('BACKUP_TIMEZONE', 'Europe/Moscow'),

    // Расписание по умолчанию — каждый день в 03:00
    'default_schedule' => '0 3 * * *',

    // Таймаут команды перед бекапом по умолчанию, секунд
    'pre_command_timeout' => 300,

    // Обзор файлов сервера: сколько элементов каталога отдавать максимум и таймаут команды, секунд
    'browse_limit' => 2000,
    'browse_timeout' => 20,

    // Предельное время одной команды бекапа (архив, дамп базы), секунд; задача целиком — не дольше 4 часов
    'run_timeout' => (int) env('BACKUP_RUN_TIMEOUT', 14000),

    // Бекап не запускается, если в локальном хранилище свободно меньше, байт (по умолчанию 1 ГБ)
    'min_free_space' => (int) env('BACKUP_MIN_FREE_SPACE', 1024 ** 3),

    // Таймаут служебных запросов к БД (проверка, списки баз и таблиц), секунд
    'db_query_timeout' => 30,

    // Шаблоны команды перед бекапом — подставляются в поле и правятся под себя (GET /backup/pre-command-templates)
    'pre_command_templates' => [
        [
            'key' => 'mysqldump_all',
            'group' => 'MySQL / MariaDB',
            'title' => 'Дамп всех баз',
            'description' => 'Консистентный дамп без блокировки InnoDB-таблиц. Учётные данные — в ~/.my.cnf.',
            'command' => 'mkdir -p /var/backups/mysql && mysqldump --all-databases --single-transaction --quick --routines --events --triggers | gzip > /var/backups/mysql/all.sql.gz',
        ],
        [
            'key' => 'mysqldump_db',
            'group' => 'MySQL / MariaDB',
            'title' => 'Дамп одной базы',
            'description' => 'Замените app на имя базы.',
            'command' => 'mkdir -p /var/backups/mysql && mysqldump --single-transaction --quick --routines --triggers app | gzip > /var/backups/mysql/app.sql.gz',
        ],
        [
            'key' => 'mysqldump_docker',
            'group' => 'MySQL / MariaDB',
            'title' => 'Дамп из Docker-контейнера',
            'description' => 'Замените mysql на имя контейнера; пароль берётся из переменной окружения контейнера.',
            'command' => "mkdir -p /var/backups/mysql && docker exec mysql sh -c 'exec mysqldump --all-databases --single-transaction --quick -uroot -p\"\$MYSQL_ROOT_PASSWORD\"' | gzip > /var/backups/mysql/all.sql.gz",
        ],
        [
            'key' => 'pg_dumpall',
            'group' => 'PostgreSQL',
            'title' => 'Дамп всех баз',
            'description' => 'Выполняется от пользователя postgres.',
            'command' => 'mkdir -p /var/backups/postgres && chown postgres /var/backups/postgres && sudo -u postgres pg_dumpall | gzip > /var/backups/postgres/all.sql.gz',
        ],
        [
            'key' => 'pg_dump_db',
            'group' => 'PostgreSQL',
            'title' => 'Дамп одной базы',
            'description' => 'Формат custom — восстановление через pg_restore. Замените app на имя базы.',
            'command' => 'mkdir -p /var/backups/postgres && chown postgres /var/backups/postgres && sudo -u postgres pg_dump -Fc app > /var/backups/postgres/app.dump',
        ],
        [
            'key' => 'pg_dump_docker',
            'group' => 'PostgreSQL',
            'title' => 'Дамп из Docker-контейнера',
            'description' => 'Замените postgres на имя контейнера.',
            'command' => 'mkdir -p /var/backups/postgres && docker exec postgres pg_dumpall -U postgres | gzip > /var/backups/postgres/all.sql.gz',
        ],
        [
            'key' => 'mongodump',
            'group' => 'Другие базы',
            'title' => 'MongoDB: mongodump',
            'description' => 'Архив всех баз.',
            'command' => 'mkdir -p /var/backups/mongo && mongodump --archive=/var/backups/mongo/all.archive.gz --gzip',
        ],
        [
            'key' => 'redis_save',
            'group' => 'Другие базы',
            'title' => 'Redis: сохранить снимок',
            'description' => 'Синхронно записывает dump.rdb (обычно /var/lib/redis) — добавьте этот каталог в бекап.',
            'command' => 'redis-cli SAVE',
        ],
        [
            'key' => 'sqlite',
            'group' => 'Другие базы',
            'title' => 'SQLite: безопасная копия',
            'description' => 'Копия базы без риска поймать её в середине записи. Замените пути.',
            'command' => "mkdir -p /var/backups/sqlite && sqlite3 /var/www/app/database.sqlite \".backup '/var/backups/sqlite/app.sqlite'\"",
        ],
        [
            'key' => 'docker_compose_stop',
            'group' => 'Приложения',
            'title' => 'Остановить docker compose',
            'description' => 'Для консистентной копии томов. Не забудьте запустить обратно после бекапа.',
            'command' => 'cd /opt/app && docker compose stop',
        ],
        [
            'key' => 'laravel_down',
            'group' => 'Приложения',
            'title' => 'Laravel: режим обслуживания',
            'description' => 'Приложение отвечает 503 на время бекапа.',
            'command' => 'cd /var/www/app && php artisan down',
        ],
        [
            'key' => 'packages_list',
            'group' => 'Система',
            'title' => 'Список установленных пакетов',
            'description' => 'Чтобы восстановить окружение на новом сервере. Добавьте /var/backups/system в каталоги.',
            'command' => 'mkdir -p /var/backups/system && (dpkg --get-selections 2>/dev/null || rpm -qa) > /var/backups/system/packages.txt && crontab -l > /var/backups/system/crontab.txt 2>/dev/null; true',
        ],
    ],
];
