<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /** Группа проектов; null — без группы */
            'group_id' => $this->group_id,
            'description' => $this->description,
            'servers_count' => $this->whenCounted('servers'),
            'databases_count' => $this->whenCounted('databases'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
