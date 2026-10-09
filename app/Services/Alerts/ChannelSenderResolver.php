<?php

namespace App\Services\Alerts;

use App\Models\AlertChannel;
use App\Services\Alerts\Senders\EmailSender;
use App\Services\Alerts\Senders\MobileSender;
use App\Services\Alerts\Senders\TelegramSender;
use App\Services\Alerts\Senders\WebPushSender;
use Illuminate\Contracts\Container\Container;

class ChannelSenderResolver
{
    private const SENDERS = [
        AlertChannel::TYPE_TELEGRAM => TelegramSender::class,
        AlertChannel::TYPE_EMAIL => EmailSender::class,
        AlertChannel::TYPE_WEBPUSH => WebPushSender::class,
        AlertChannel::TYPE_MOBILE => MobileSender::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function for(AlertChannel $channel): ChannelSender
    {
        return $this->container->make(self::SENDERS[$channel->type]);
    }
}
