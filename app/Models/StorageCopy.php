<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Копия файла в хранилище. `uploadable` — любая модель-владелец (например, бекап).
 */
#[Fillable(['uploadable_type', 'uploadable_id', 'storage_target_id', 'remote_path', 'status', 'size', 'md5', 'error', 'attempts', 'uploaded_at'])]
class StorageCopy extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UPLOADING = 'uploading';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    public function uploadable(): MorphTo
    {
        return $this->morphTo();
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(StorageTarget::class, 'storage_target_id');
    }
}
