<?php

namespace App\Services\Alerts;

use App\Jobs\SendAlertDelivery;
use App\Models\AlertDelivery;
use App\Models\AlertRule;

/** По событию находит подходящие правила и ставит отправки в очередь (с учётом фильтров, тихих часов и антиспама). */
class AlertDispatcher
{
    /** @return list<AlertDelivery> */
    public function dispatch(AlertMessage $message): array
    {
        $deliveries = [];

        $rules = AlertRule::query()->where('event', $message->event)->where('enabled', true)->with('channels')->get();

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $message)) {
                continue;
            }

            $skip = match (true) {
                $rule->inQuietHours() => 'Тихие часы',
                $this->throttled($rule, $message) => "Антиспам: не чаще раза в {$rule->throttle_minutes} мин на объект",
                default => null,
            };

            foreach ($rule->channels->where('enabled', true) as $channel) {
                $delivery = AlertDelivery::create([
                    'alert_rule_id' => $rule->id,
                    'alert_channel_id' => $channel->id,
                    'event' => $message->event,
                    'object_key' => $message->objectKey,
                    'title' => $message->title,
                    'body' => $message->body,
                    'url' => $message->url,
                    'status' => $skip === null ? AlertDelivery::STATUS_PENDING : AlertDelivery::STATUS_SKIPPED,
                    'error' => $skip,
                ]);

                if ($skip === null) {
                    SendAlertDelivery::dispatch($delivery->id);
                }
                $deliveries[] = $delivery;
            }
        }

        return $deliveries;
    }

    private function matches(AlertRule $rule, AlertMessage $message): bool
    {
        return $rule->target_type === null || $message->targetId($rule->target_type) === $rule->target_id;
    }

    private function throttled(AlertRule $rule, AlertMessage $message): bool
    {
        if ($rule->throttle_minutes <= 0 || $message->objectKey === null) {
            return false;
        }

        return AlertDelivery::query()
            ->where('alert_rule_id', $rule->id)
            ->where('object_key', $message->objectKey)
            ->whereIn('status', [AlertDelivery::STATUS_PENDING, AlertDelivery::STATUS_SENT])
            ->where('created_at', '>', now()->subMinutes($rule->throttle_minutes))
            ->exists();
    }
}
