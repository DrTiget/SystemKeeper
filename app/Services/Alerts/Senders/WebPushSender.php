<?php

namespace App\Services\Alerts\Senders;

use App\Models\AlertChannel;
use App\Models\PushSubscription;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\AlertSkippedException;
use App\Services\Alerts\ChannelSender;
use App\Services\Alerts\WebPushClient;
use RuntimeException;
use Throwable;

/** Рассылает push всем браузерным подпискам; устаревшие подписки удаляются. */
class WebPushSender implements ChannelSender
{
    public function __construct(private readonly WebPushClient $client) {}

    public function send(AlertChannel $channel, AlertMessage $message): void
    {
        $subscriptions = PushSubscription::query()->get();
        if ($subscriptions->isEmpty()) {
            throw new AlertSkippedException('Нет подписанных браузеров');
        }

        $payload = json_encode(['title' => $message->title, 'body' => $message->body, 'url' => $message->url], JSON_UNESCAPED_UNICODE);
        $errors = [];

        foreach ($subscriptions as $subscription) {
            try {
                if (! $this->client->send($subscription, $payload)) {
                    $subscription->delete();
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        // Ошибка только если не дошло ни до одной подписки
        if (count($errors) === $subscriptions->count()) {
            throw new RuntimeException($errors[0]);
        }
    }
}
