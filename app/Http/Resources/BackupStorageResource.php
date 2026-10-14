<?php

namespace App\Http\Resources;

use App\Models\Backup;
use App\Models\BackupStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BackupStorage
 */
class BackupStorageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $backups = $this->backups()->where('status', Backup::SUCCESS);

        return [
            'id' => $this->id,
            'name' => $this->name,
            /** local — диск SystemKeeper */
            'type' => $this->type,
            'is_default' => $this->is(BackupStorage::default()),
            /** Диск хранилища, байт; null — неизвестно */
            'disk' => $this->disk(),
            /** Занято бекапами, байт */
            'used' => (int) $backups->clone()->sum('size'),
            'backups_count' => $backups->clone()->count(),
            /**
             * Что бекапится в это хранилище: занятый объём и настройки хранения каждого источника.
             *
             * @var list<array{type: string, id: int, name: string, used: int, backups_count: int, keep_count: int, max_size: int|null}>
             */
            'sources' => $this->when($this->resource->relationLoaded('sources') || $request->route('storage'), fn () => $this->resource->sources()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
