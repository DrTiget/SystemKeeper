<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Синхронизация хранилища: в него копируются успешные бекапы, которых там ещё нет.
 */
#[Fillable(['user_id', 'status', 'add_to_sources', 'total', 'done', 'failed', 'bytes_total', 'bytes_done', 'current', 'error', 'started_at', 'finished_at'])]
class StorageSync extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'add_to_sources' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(BackupStorage::class, 'storage_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }
}
