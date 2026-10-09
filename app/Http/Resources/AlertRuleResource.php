<?php

namespace App\Http\Resources;

use App\Models\AlertRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AlertRule
 */
class AlertRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'event' => $this->event,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'channel_ids' => $this->channels->pluck('id')->values(),
            'channels' => $this->channels->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'type' => $c->type])->values(),
            'quiet_hours_start' => $this->quiet_hours_start,
            'quiet_hours_end' => $this->quiet_hours_end,
            'throttle_minutes' => $this->throttle_minutes,
            'enabled' => $this->enabled,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
