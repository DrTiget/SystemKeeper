<?php

namespace App\Http\Resources;

use App\Models\Monitor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Monitor
 */
class MonitorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'target' => $this->target,
            'interval_seconds' => $this->interval_seconds,
            'timeout_seconds' => $this->timeout_seconds,
            'expected_status' => $this->expected_status,
            'keyword' => $this->keyword,
            'ssl_expiry_days' => $this->ssl_expiry_days,
            'heartbeat_token' => $this->heartbeat_token,
            'heartbeat_url' => $this->heartbeat_token ? url("/api/v1/heartbeat/{$this->heartbeat_token}") : null,
            'heartbeat_grace_seconds' => $this->isHeartbeat() ? $this->heartbeat_grace_seconds : null,
            'last_heartbeat_at' => $this->last_heartbeat_at,
            'failure_threshold' => $this->failure_threshold,
            'enabled' => $this->enabled,
            'status' => $this->last_status,
            'consecutive_failures' => $this->consecutive_failures,
            'last_checked_at' => $this->last_checked_at,
            'next_check_at' => $this->next_check_at,
            'open_incident' => $this->whenLoaded('openIncident', fn () => $this->openIncident ? new IncidentResource($this->openIncident) : null),
            'uptime' => $this->when(array_key_exists('uptime', $this->resource->getAttributes()), fn () => $this->resource->getAttribute('uptime')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
