<?php

namespace App\Services\Alerts;

use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AlertPending;
use App\Models\AlertRule;
use Throwable;

/**
 * Движок правил: раз в минуту оценивает все включённые правила и ведёт жизнь алертов.
 *
 * - Условие должно продержаться `duration` минут (отсчёт — `alert_pendings`, по паре «правило + объект»), тогда создаётся
 *   алерт `firing` и уходит уведомление во все включённые каналы правила. На пару «правило + объект» — один активный алерт.
 * - Пока алерт активен и не взят в работу, а у правила есть `repeat_every`, уведомление повторяется.
 * - Условие ушло — алерт `resolved` и (при `notify_resolved`) уведомление. Нет данных (`breached = null`) — ничего не меняется.
 * - Выключенное правило и объект, выпавший из области (удалён, убран из targets), закрывают алерты молча.
 * - Ошибка в одном правиле не мешает остальным.
 */
class AlertEngine
{
    /** Запас на дрожание планировщика: проверка раз в минуту, а срок «ровно N минут» может прийтись на 59-ю секунду */
    private const TOLERANCE_SECONDS = 30;

    public function __construct(private ConditionEvaluator $evaluator) {}

    /** @return array{fired: int, repeated: int, resolved: int} */
    public function run(): array
    {
        $stats = ['fired' => 0, 'repeated' => 0, 'resolved' => 0];

        $this->closeDisabledRules($stats);

        AlertRule::query()->where('enabled', true)->with('channels')->orderBy('id')->each(function (AlertRule $rule) use (&$stats) {
            try {
                $this->evaluate($rule, $stats);
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $stats;
    }

    /** @param array{fired: int, repeated: int, resolved: int} $stats */
    private function closeDisabledRules(array &$stats): void
    {
        $disabled = AlertRule::query()->where('enabled', false)->pluck('id');
        AlertPending::query()->whereIn('alert_rule_id', $disabled)->delete();
        Alert::query()->where('status', Alert::FIRING)->whereIn('alert_rule_id', $disabled)
            ->each(function (Alert $alert) use (&$stats) {
                $this->resolve($alert, null);
                $stats['resolved']++;
            });
    }

    /** @param array{fired: int, repeated: int, resolved: int} $stats */
    private function evaluate(AlertRule $rule, array &$stats): void
    {
        $observations = collect($this->evaluator->observe($rule))->keyBy(fn (Observation $o) => $o->key());
        $active = Alert::query()->where('alert_rule_id', $rule->id)->where('status', Alert::FIRING)->get()->keyBy(fn (Alert $a) => "{$a->object_type}:{$a->object_id}");
        $pendings = AlertPending::query()->where('alert_rule_id', $rule->id)->get()->keyBy(fn (AlertPending $p) => "{$p->object_type}:{$p->object_id}");
        $now = now();

        foreach ($observations as $key => $o) {
            if ($o->breached === null) {
                continue;
            }
            $alert = $active[$key] ?? null;

            if (! $o->breached) {
                $pendings->get($key)?->delete();
                if ($alert) {
                    $this->resolve($alert, $rule->notify_resolved ? $rule : null);
                    $stats['resolved']++;
                }

                continue;
            }

            $pending = $pendings[$key] ?? AlertPending::create(['alert_rule_id' => $rule->id, 'object_type' => $o->type, 'object_id' => $o->id, 'since' => $now]);

            if ($alert) {
                $this->refresh($alert, $rule, $o);
                if ($this->shouldRepeat($alert, $rule)) {
                    $this->notify($alert, $rule, AlertNotification::REPEAT);
                    $stats['repeated']++;
                }
            } elseif ($pending->since->clone()->addMinutes($rule->duration)->subSeconds(self::TOLERANCE_SECONDS)->lte($now)) {
                $this->fire($rule, $o);
                $stats['fired']++;
            }
        }

        // объекта больше нет в области правила — закрываем молча
        foreach ($active as $key => $alert) {
            if (! isset($observations[$key])) {
                $this->resolve($alert, null);
                $stats['resolved']++;
            }
        }
        foreach ($pendings as $key => $pending) {
            if (! isset($observations[$key])) {
                $pending->delete();
            }
        }
    }

    private function fire(AlertRule $rule, Observation $o): void
    {
        $alert = Alert::create([
            'alert_rule_id' => $rule->id,
            'severity' => $rule->severity,
            'status' => Alert::FIRING,
            'object_type' => $o->type,
            'object_id' => $o->id,
            'object_name' => $o->name,
            'project_id' => $o->projectId,
            'message' => $o->message,
            'started_at' => now(),
        ]);
        $rule->forceFill(['last_fired_at' => now()])->save();

        $this->notify($alert, $rule, AlertNotification::FIRING);
    }

    /** Свежие значения в активном алерте: сообщение, название объекта, важность, проект. */
    private function refresh(Alert $alert, AlertRule $rule, Observation $o): void
    {
        $alert->fill(['severity' => $rule->severity, 'object_name' => $o->name, 'project_id' => $o->projectId, 'message' => $o->message]);
        if ($alert->isDirty()) {
            $alert->save();
        }
    }

    private function shouldRepeat(Alert $alert, AlertRule $rule): bool
    {
        if (! $rule->repeat_every || $alert->acknowledged_at) {
            return false;
        }
        $last = $alert->last_notified_at ?? $alert->started_at;

        return $last->clone()->addMinutes($rule->repeat_every)->subSeconds(self::TOLERANCE_SECONDS)->lte(now());
    }

    /** Закрывает алерт; $notifyRule — правило, чьи каналы уведомить о решении (null — молча). */
    private function resolve(Alert $alert, ?AlertRule $notifyRule): void
    {
        $alert->forceFill(['status' => Alert::RESOLVED, 'resolved_at' => now()])->save();
        if ($notifyRule) {
            $this->notify($alert, $notifyRule, AlertNotification::RESOLVED);
        }
    }

    private function notify(Alert $alert, AlertRule $rule, string $event): void
    {
        $alert->forceFill(['last_notified_at' => now()])->save();
        foreach ($rule->channels as $channel) {
            if ($channel->enabled) {
                SendAlertNotification::dispatch($alert->id, $channel->id, $event);
            }
        }
    }
}
