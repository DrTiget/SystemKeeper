<?php

namespace App\Http\Resources;

use App\Models\Incident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Incident
 */
class IncidentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'monitor_id' => $this->monitor_id,
            'monitor' => $this->whenLoaded('monitor', fn () => ['id' => $this->monitor->id, 'name' => $this->monitor->name, 'type' => $this->monitor->type]),
            'status' => $this->isOpen() ? 'open' : 'resolved',
            'started_at' => $this->started_at,
            'resolved_at' => $this->resolved_at,
            'reason' => $this->reason,
            'duration_seconds' => $this->durationSeconds(),
        ];
    }
}
