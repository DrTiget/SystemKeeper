<?php

namespace App\Services\Alerts;

use App\Models\AlertChannel;

interface ChannelSender
{
    /**
     * @throws AlertSkippedException если отправка отключена
     * @throws \Throwable при сбое отправки (задание повторится)
     */
    public function send(AlertChannel $channel, AlertMessage $message): void;
}
