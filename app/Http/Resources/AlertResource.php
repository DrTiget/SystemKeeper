<?php

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alert
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule' => ['id' => $this->alert_rule_id, 'name' => $this->rule->name, 'kind' => $this->rule->kind],
            'severity' => $this->severity,
            /** firing — идёт сейчас; resolved — решён */
            'status' => $this->status,
            /** server, monitor, database или storage; название — на момент последней оценки */
            'object' => [
                'type' => $this->object_type,
                'id' => $this->object_id,
                'name' => $this->object_name,
                'project' => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name] : null,
            ],
            /** «CPU 97% дольше 5 мин», «Диск / заполнен на 91%», «Сайт example.com недоступен: …» */
            'message' => $this->message,
            'started_at' => $this->started_at,
            'resolved_at' => $this->resolved_at,
            'acknowledged_at' => $this->acknowledged_at,
            'acknowledged_by' => $this->acknowledger ? ['id' => $this->acknowledger->id, 'name' => $this->acknowledger->name] : null,
            /** Отправленные уведомления; `event` — firing, repeat или resolved */
            'notifications' => $this->notifications->map(fn ($n) => [
                'channel' => ['id' => $n->channel->id, 'name' => $n->channel->name, 'type' => $n->channel->type],
                'event' => $n->event,
                'at' => $n->created_at,
                'ok' => $n->ok,
                'error' => $n->error,
            ])->values()->all(),
        ];
    }
}
