<?php

namespace App\Services\Alerts;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

class MinishlinkWebPushClient implements WebPushClient
{
    public function send(PushSubscription $subscription, string $payload): bool
    {
        $vapid = config('alerts.vapid');
        if (empty($vapid['public_key']) || empty($vapid['private_key'])) {
            throw new RuntimeException('VAPID-ключи не заданы (php artisan alerts:vapid-keys)');
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => $vapid['subject'],
            'publicKey' => $vapid['public_key'],
            'privateKey' => $vapid['private_key'],
        ]]);

        $report = $webPush->sendOneNotification(
            Subscription::create(['endpoint' => $subscription->endpoint, 'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth]]),
            $payload,
        );

        if ($report->isSuccess()) {
            return true;
        }
        if ($report->isSubscriptionExpired()) {
            return false;
        }

        throw new RuntimeException('Web Push: '.$report->getReason());
    }
}
