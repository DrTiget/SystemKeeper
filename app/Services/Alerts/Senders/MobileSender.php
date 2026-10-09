<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Models\DeviceToken;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\AlertSkippedException;
use App\Services\Alerts\ChannelSender;
use App\Services\Alerts\FcmDriver;

class MobileSender implements ChannelSender
{
    public function __construct(private readonly FcmDriver $fcm) {}

    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        if (! $this->fcm->enabled()) {
            throw new AlertSkippedException('Отправка в мобильное приложение (FCM) отключена');
        }

        $tokens = DeviceToken::query()->pluck('token')->all();
        if ($tokens === []) {
            throw new AlertSkippedException('Нет зарегистрированных устройств');
        }

        $this->fcm->send($tokens, $message->title, $message->body, array_filter(['url' => $message->url, 'event' => $message->event]));
    }
}
