<?php

namespace App\Jobs;

use App\Models\AlertDelivery;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\AlertSkippedException;
use App\Services\Alerts\ChannelSenderResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class SendAlertDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly int $deliveryId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ChannelSenderResolver $senders): void
    {
        $delivery = AlertDelivery::with('channel')->find($this->deliveryId);
        if ($delivery === null || $delivery->status !== AlertDelivery::STATUS_PENDING) {
            return;
        }
        if ($delivery->channel === null || ! $delivery->channel->enabled) {
            $delivery->update(['status' => AlertDelivery::STATUS_SKIPPED, 'error' => 'Канал удалён или выключен']);

            return;
        }

        $delivery->increment('attempts');

        try {
            $senders->for($delivery->channel)->send($delivery->channel, new AlertMessage($delivery->event, $delivery->title, $delivery->body, $delivery->url, $delivery->object_key));
            $delivery->update(['status' => AlertDelivery::STATUS_SENT, 'error' => null, 'sent_at' => now()]);
        } catch (AlertSkippedException $e) {
            $delivery->update(['status' => AlertDelivery::STATUS_SKIPPED, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $delivery->update(['error' => Str::limit($e->getMessage(), 1000)]);

            throw $e;
        }
    }

    /** Ретраи исчерпаны. */
    public function failed(Throwable $e): void
    {
        AlertDelivery::query()->whereKey($this->deliveryId)->update([
            'status' => AlertDelivery::STATUS_FAILED,
            'error' => Str::limit($e->getMessage(), 1000),
        ]);
    }
}
