<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Копия бекапа в одном из хранилищ: удалась или нет (с ошибкой).
 */
#[Fillable(['storage_id', 'status', 'error'])]
class BackupCopy extends Model
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function storage(): BelongsTo
    {
        return $this->belongsTo(BackupStorage::class);
    }
}
