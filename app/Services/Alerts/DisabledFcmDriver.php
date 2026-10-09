<?php

namespace App\Services\Alerts;

/** Заглушка: ничего не отправляет. */
class DisabledFcmDriver implements FcmDriver
{
    public function enabled(): bool
    {
        return false;
    }

    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        throw new AlertSkippedException('Отправка в мобильное приложение (FCM) отключена');
    }
}
