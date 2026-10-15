<?php

return [
    // Версия сборки — задаётся при сборке прод-образа (ARG APP_VERSION); в разработке — dev
    'version' => env('APP_VERSION', 'dev'),

    // Репозиторий GitHub, в релизах которого ищутся обновления
    'repository' => env('SK_REPO', 'DrTiget/SystemKeeper'),

    // Проверять ли новые релизы (раз в 10 минут, планировщик)
    'update_check' => (bool) env('SK_UPDATE_CHECK', true),

    // Общий с контейнером updater каталог: request.json, status.json, heartbeat
    'updater_path' => storage_path('app/updater'),

    // Updater считается запущенным, если обновлял heartbeat не позже, секунд
    'updater_heartbeat' => 60,

    // Адрес SystemKeeper, доступный из интернета (для временных ссылок, по которым облака сами скачивают бекапы);
    // по умолчанию — APP_URL. localhost и внутренние адреса не используются.
    'public_url' => env('SK_PUBLIC_URL'),

    // Сколько живёт временная ссылка на файл бекапа, минут
    'transfer_link_ttl' => (int) env('SK_TRANSFER_LINK_TTL', 180),
];
