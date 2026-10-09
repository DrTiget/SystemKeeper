<?php

namespace App\Services\Alerts;

use App\Models\PushSubscription;

interface WebPushClient
{
    /**
     * Отправляет push одной подписке.
     *
     * @return bool false — подписка больше недействительна (её нужно удалить)
     *
     * @throws \Throwable при временном сбое
     */
    public function send(PushSubscription $subscription, string $payload): bool;
}
