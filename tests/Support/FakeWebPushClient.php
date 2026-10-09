<?php

namespace Tests\Support;

use App\Models\PushSubscription;
use App\Services\Alerts\WebPushClient;
use RuntimeException;

class FakeWebPushClient implements WebPushClient
{
    /** @var list<array{endpoint: string, payload: array<string, mixed>}> */
    public array $sent = [];

    /** @var list<string> endpoint'ы, для которых подписка считается истёкшей */
    public array $expired = [];

    public bool $fail = false;

    public function send(PushSubscription $subscription, string $payload): bool
    {
        if ($this->fail) {
            throw new RuntimeException('push service unavailable');
        }
        if (in_array($subscription->endpoint, $this->expired, true)) {
            return false;
        }
        $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => json_decode($payload, true)];

        return true;
    }
}
