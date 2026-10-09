<?php

namespace App\Http\Resources;

use App\Models\StorageTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StorageTarget
 */
class StorageTargetResource extends JsonResource
{
    /**
     * Токен в ответ не попадает никогда — только флаг has_token.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'base_path' => $this->base_path,
            'enabled' => $this->enabled,
            'has_token' => $this->token !== null,
            'last_checked_at' => $this->last_checked_at,
            'last_check_status' => $this->last_check_status,
            'last_check_error' => $this->last_check_error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
