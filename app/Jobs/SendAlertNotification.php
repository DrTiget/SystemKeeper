<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\AlertChannel;
use App\Models\AlertNotification;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\ChannelSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Отправляет уведомление об алерте в один канал и записывает результат (ok / ошибка) в журнал и в канал.
 * Ошибка отправки не падает джобом: она сохранена в `last_error` канала, а повторит её `repeat_every` правила.
 */
class SendAlertNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $alertId, public int $channelId, public string $event) {}

    public function handle(ChannelSender $sender): void
    {
        $alert = Alert::query()->with('rule')->find($this->alertId);
        $channel = AlertChannel::query()->find($this->channelId);
        if (! $alert || ! $channel || ! $channel->enabled) {
            return;
        }

        $error = null;
        try {
            $sender->send($channel, AlertMessage::for($alert, $this->event), $this->payload($alert));
        } catch (Throwable $e) {
            $error = $e->getMessage() ?: 'Не удалось отправить';
        }

        AlertNotification::create([
            'alert_id' => $alert->id,
            'alert_channel_id' => $channel->id,
            'event' => $this->event,
            'ok' => $error === null,
            'error' => $error,
            'created_at' => now(),
        ]);
        $channel->forceFill(['last_error' => $error] + ($error === null ? ['last_sent_at' => now()] : []))->save();
    }

    /** @return array<string, mixed> */
    private function payload(Alert $alert): array
    {
        return [
            'event' => $this->event,
            'alert' => [
                'id' => $alert->id,
                'status' => $alert->status,
                'severity' => $alert->severity,
                'message' => $alert->message,
                'rule' => ['id' => $alert->alert_rule_id, 'name' => $alert->rule?->name, 'kind' => $alert->rule?->kind],
                'object' => ['type' => $alert->object_type, 'id' => $alert->object_id, 'name' => $alert->object_name],
                'started_at' => $alert->started_at->toIso8601String(),
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
            ],
        ];
    }
}
