<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * Запуск бекапа сервера или базы данных: статус, журнал и получившиеся файлы.
 * Файлы лежат на диске `backups` в каталоге `path`.
 */
#[Fillable(['trigger', 'status', 'user_id', 'started_at', 'finished_at', 'path', 'files', 'size', 'log', 'error'])]
class Backup extends Model
{
    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    /** @var array<string, mixed> */
    protected $attributes = ['log' => '[]', 'files' => '[]'];

    protected static function booted(): void
    {
        static::deleting(function (Backup $backup) {
            if ($backup->path) {
                Storage::disk('backups')->deleteDirectory($backup->path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'files' => 'array',
            'size' => 'integer',
            'log' => 'array',
        ];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** Строка журнала с отметкой времени; сохраняется сразу — чтобы ход бекапа был виден в API. */
    public function appendLog(string $message): void
    {
        $this->log = [...($this->log ?? []), ['at' => now()->toIso8601String(), 'message' => $message]];
        $this->save();
    }
}
