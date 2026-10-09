<?php

return [
    // Адрес фронтенда для ссылок в сообщениях
    'frontend_url' => rtrim((string) env('APP_FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),

    // Web Push (VAPID): ключи генерирует `php artisan alerts:vapid-keys`
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'http://localhost')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    // Мобильное приложение (FCM): пока заготовка, отправка выключена
    'fcm' => [
        'enabled' => (bool) env('FCM_ENABLED', false),
    ],

    // Сколько дней хранить журнал отправок
    'delivery_retention_days' => (int) env('ALERT_DELIVERY_RETENTION_DAYS', 90),
];
