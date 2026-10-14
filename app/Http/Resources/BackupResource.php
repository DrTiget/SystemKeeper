<?php

namespace App\Http\Resources;

use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Backup
 */
class BackupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** server | database */
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            /** manual | schedule */
            'trigger' => $this->trigger,
            /** queued | running | success | failed */
            'status' => $this->status,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            /** @var list<array{name: string, size: int}> */
            'files' => $this->files ?? [],
            'size' => $this->size,
            /** @var list<array{at: string, message: string}> */
            'log' => $this->log ?? [],
            'error' => $this->error,
            'created_at' => $this->created_at,
        ];
    }
}
