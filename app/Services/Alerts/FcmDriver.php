<?php

namespace App\Services\Alerts;

/** Драйвер push для мобильного приложения (FCM). Реальная отправка подключается позже. */
interface FcmDriver
{
    public function enabled(): bool;

    /**
     * @param  list<string>  $tokens
     * @param  array<string, string>  $data
     */
    public function send(array $tokens, string $title, string $body, array $data = []): void;
}
