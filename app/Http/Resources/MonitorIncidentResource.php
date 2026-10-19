<?php

namespace App\Http\Resources;

use App\Models\MonitorIncident;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MonitorIncident
 */
class MonitorIncidentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'started_at' => $this->started_at,
            /** null — сбой продолжается */
            'ended_at' => $this->ended_at,
            /** Первая ошибка сбоя */
            'error' => $this->error,
        ];
    }
}
