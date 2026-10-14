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
        $backups = $this->backups()->where('backups.status', Backup::SUCCESS);

        return [
            'id' => $this->id,
            'name' => $this->name,
            /** local — диск SystemKeeper, s3 — бакет S3 */
            'type' => $this->type,
            /** Параметры S3 без секретного ключа; null у диска */
            's3' => $this->type === BackupStorage::S3 ? [
                'endpoint' => $this->settings['endpoint'] ?? null,
                'region' => $this->settings['region'] ?? null,
                'bucket' => $this->settings['bucket'] ?? null,
                'prefix' => $this->settings['prefix'] ?? '',
                'path_style' => (bool) ($this->settings['path_style'] ?? false),
                'key' => $this->settings['key'] ?? null,
                'has_secret' => filled($this->settings['secret'] ?? null),
            ] : null,
            'is_default' => $this->is(BackupStorage::default()),
            /** Диск хранилища, байт; null — неизвестно */
            'disk' => $this->disk(),
            /** Занято бекапами, байт */
            'used' => (int) $backups->clone()->sum('backups.size'),
            'backups_count' => $backups->clone()->count(),
            /**
             * Что бекапится в это хранилище: занятый объём и настройки хранения каждого источника.
             *
             * @var list<array{type: string, id: int, name: string, project: array{id: int, name: string, group: array{id: int, name: string}|null}|null, used: int, backups_count: int, keep_count: int, max_size: int|null}>
             */
            'sources' => $this->when($this->resource->relationLoaded('sources') || $request->route('storage'), fn () => $this->resource->sources()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
