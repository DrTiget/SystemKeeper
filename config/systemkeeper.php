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
];
