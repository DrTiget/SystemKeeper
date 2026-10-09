<?php

namespace App\Http\Resources;

use App\Models\MonitorCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MonitorCheck
 */
class MonitorCheckResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'monitor_id' => $this->monitor_id,
            'status' => $this->status,
            'response_time_ms' => $this->response_time_ms,
            'status_code' => $this->status_code,
            'error' => $this->error,
            'checked_at' => $this->checked_at,
        ];
    }
}
