<?php

namespace App\Models;

use Database\Factories\BackupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'backup_job_id', 'status', 'trigger', 'file_path', 'size_bytes', 'sha256', 'md5',
    'started_at', 'finished_at', 'error', 'log',
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

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULE = 'schedule';

    protected static function booted(): void
    {
        static::deleting(fn (Backup $backup) => $backup->deleteFile());
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(BackupJob::class, 'backup_job_id');
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
