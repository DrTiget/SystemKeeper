<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Запуск бекапа сервера или базы данных: статус, журнал и получившиеся файлы.
 * Файлы лежат на диске `backups` в каталоге `path`.
 */
#[Fillable(['trigger', 'status', 'user_id', 'storage_id', 'started_at', 'finished_at', 'path', 'files', 'size', 'log', 'error'])]
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
        static::deleting(fn (Backup $backup) => $backup->removeStoredFiles());
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

    public function storage(): BelongsTo
    {
        return $this->belongsTo(BackupStorage::class);
    }

    /** Удаляет файлы, оставляя запись в истории (неудачный бекап, вытесненный по объёму). */
    public function deleteFiles(): void
    {
        $this->removeStoredFiles();
        $this->forceFill(['files' => [], 'size' => null])->save();
    }

    /**
     * Удаляет каталог бекапа в его хранилище и локальную копию (во время выгрузки в облако она лежит на диске).
     * Ошибку облака не пробрасываем — запись всё равно удаляется, а потерянные файлы попадут в лог.
     */
    private function removeStoredFiles(): void
    {
        if (! $this->path) {
            return;
        }
        Storage::disk('backups')->deleteDirectory($this->path);
        if ($this->storage && $this->storage->type !== BackupStorage::LOCAL) {
            try {
                $this->storage->filesystem()->deleteDirectory($this->path);
            } catch (Throwable $e) {
                Log::warning('Не удалось удалить файлы бекапа в облаке', ['backup' => $this->id, 'path' => $this->path, 'error' => $e->getMessage()]);
            }
        }
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
