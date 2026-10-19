<?php

namespace App\Services\Alerts;

use App\Models\AlertRule;
use App\Models\Project;
use Throwable;

/**
 * Тестовое уведомление по правилу: пример сработавшего алерта (вид, важность, условие, первый объект из области правила)
 * уходит синхронно во все каналы правила. Алерты в БД не создаются, `last_fired_at` правила не меняется.
 */
class RuleTestNotifier
{
    public function __construct(private ConditionEvaluator $evaluator, private ChannelSender $sender) {}

    /** @return list<array{channel: array{id: int, name: string, type: string}, ok: bool, error: ?string}> */
    public function send(AlertRule $rule): array
    {
        $message = $this->message($rule);
        $payload = $this->payload($rule, $message);

        $results = [];
        foreach ($rule->channels()->orderBy('alert_channels.id')->get() as $channel) {
            $error = null;
            try {
                $this->sender->send($channel, $message, $payload);
            } catch (Throwable $e) {
                $error = $e->getMessage() ?: 'Не удалось отправить';
            }
            $channel->forceFill(['last_error' => $error] + ($error === null ? ['last_sent_at' => now()] : []))->save();
            $results[] = ['channel' => ['id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type], 'ok' => $error === null, 'error' => $error];
        }

        return $results;
    }

    public function message(AlertRule $rule): AlertMessage
    {
        $type = AlertKinds::objects($rule->kind)[0] ?? 'server';
        $name = 'Пример объекта';
        $project = $rule->project?->name;

        // первый объект из области правила; не получилось определить — остаётся пример
        try {
            $first = $this->evaluator->observe($rule)[0] ?? null;
        } catch (Throwable) {
            $first = null;
        }
        if ($first) {
            [$type, $name] = [$first->type, $first->name];
            $project = $first->projectId ? Project::query()->whereKey($first->projectId)->value('name') : null;
        }

        return AlertMessage::forRule($rule, $type, $name, $project, $this->sample($rule, $name));
    }

    /** @return array<string, mixed> */
    private function payload(AlertRule $rule, AlertMessage $message): array
    {
        return [
            'event' => 'test',
            'alert' => [
                'id' => null,
                'status' => 'firing',
                'severity' => $rule->severity,
                'message' => $message->message,
                'rule' => ['id' => $rule->id, 'name' => $rule->name, 'kind' => $rule->kind],
                'object' => ['type' => $message->objectType, 'id' => null, 'name' => $message->objectName],
                'started_at' => $message->startedAt?->toIso8601String(),
                'resolved_at' => null,
            ],
        ];
    }

    /** Сообщение так, как его составил бы движок, при значении чуть хуже порога. */
    private function sample(AlertRule $rule, string $name): string
    {
        $threshold = $rule->threshold;
        $lasting = $rule->duration > 0 ? " дольше {$rule->duration} мин" : '';
        $percent = fn (float $over) => (int) round(min(100, ($threshold ?? 90) + $over));

        return match ($rule->kind) {
            AlertKinds::SERVER_CPU => 'CPU '.$percent(5).'%'.$lasting,
            AlertKinds::SERVER_MEMORY => 'Память занята на '.$percent(5).'%'.$lasting,
            AlertKinds::SERVER_DISK => 'Диск / заполнен на '.$percent(3).'%',
            AlertKinds::SERVER_LOAD => 'Нагрузка '.number_format(($threshold ?? 2) + 0.5, 2, ',', '').' на ядро'.$lasting,
            AlertKinds::SERVER_UNREACHABLE => "Сервер {$name} не отвечает: connection timed out",
            AlertKinds::MONITOR_DOWN => "{$name} недоступен: connection refused",
            AlertKinds::MONITOR_SSL => "Сертификат {$name} истекает через ".max(0, (int) ceil(($threshold ?? 14) / 2)).' дн.',
            AlertKinds::BACKUP_FAILED => "Бекап {$name} не удался: пример ошибки",
            AlertKinds::BACKUP_MISSING => "Нет успешного бекапа {$name} уже ".((int) ceil(($threshold ?? 24) + 2)).' ч',
            AlertKinds::STORAGE_FULL => "Хранилище {$name} заполнено на ".$percent(3).'%',
            default => AlertKinds::describe($rule->kind, $threshold, $rule->duration),
        };
    }
}
