<?php

namespace App\Models;

use Database\Factories\BackupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'backup_job_id', 'status', 'trigger', 'file_path', 'size_bytes', 'sha256', 'md5',
    'started_at', 'finished_at', 'error', 'log', 'restore_status', 'restore_checked_at', 'restore_log',
])]
class Backup extends Model
{
    /** @use HasFactory<BackupFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    /** Статусы проверки восстановления: null — не проверялся */
    public const RESTORE_PENDING = 'pending';

    public const RESTORE_RUNNING = 'running';

    public const RESTORE_OK = 'ok';

    public const RESTORE_FAILED = 'failed';

    public const RESTORE_SKIPPED = 'skipped';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULE = 'schedule';

    protected static function booted(): void
    {
        static::deleting(function (Backup $backup) {
            $backup->deleteFile();
            // Записи о копиях; сами файлы в хранилищах удаляет BackupPurger
            $backup->copies()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'restore_checked_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
    }

    public function copies(): MorphMany
    {
        return $this->morphMany(StorageCopy::class, 'uploadable');
    }

    /** Идёт ли выгрузка копий в хранилища (ожидает в очереди или выполняется). */
    public function hasActiveCopies(): bool
    {
        // Копии, застрявшие дольше upload_stale_hours (воркер потерял задание), выгрузкой не считаем
        return $this->copies()
            ->whereIn('status', [StorageCopy::STATUS_PENDING, StorageCopy::STATUS_UPLOADING])
            ->where('updated_at', '>', now()->subHours((int) config('backup.upload_stale_hours')))
            ->exists();
    }

    public function isRestoreActive(): bool
    {
        return in_array($this->restore_status, [self::RESTORE_PENDING, self::RESTORE_RUNNING], true)
            && $this->updated_at->gt(now()->subHours((int) config('backup.upload_stale_hours')));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function fileExists(): bool
    {
        return $this->file_path !== null && Storage::disk(config('backup.disk'))->exists($this->file_path);
    }

    public function deleteFile(): void
    {
        if ($this->file_path !== null) {
            Storage::disk(config('backup.disk'))->delete($this->file_path);
        }
    }
}
