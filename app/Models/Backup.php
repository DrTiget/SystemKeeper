<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Запуск бекапа сервера или базы данных: статус, журнал и получившиеся файлы.
 * Файлы лежат на диске `backups` в каталоге `path`.
 */
#[Fillable(['trigger', 'status', 'user_id', 'started_at', 'finished_at', 'path', 'files', 'meta', 'size', 'log', 'error'])]
class Backup extends Model
{
    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULE = 'schedule';

    /** По ссылке-хуку (из CI/CD) */
    public const TRIGGER_HOOK = 'hook';

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
            'meta' => 'array',
            'size' => 'integer',
            'log' => 'array',
        ];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Копии бекапа по хранилищам */
    public function copies(): HasMany
    {
        return $this->hasMany(BackupCopy::class);
    }

    /** Хранилище, откуда отдавать файлы: удачная копия, диск SystemKeeper — в первую очередь. */
    public function readableStorage(): ?BackupStorage
    {
        return $this->copies->where('status', BackupCopy::SUCCESS)->map->storage->filter()
            ->sortBy(fn (BackupStorage $s) => $s->type === BackupStorage::LOCAL ? 0 : 1)->first();
    }

    /** Удаляет файлы, оставляя запись в истории (неудачный бекап, вытесненный по объёму). */
    public function deleteFiles(): void
    {
        $this->removeStoredFiles();
        $this->forceFill(['files' => [], 'size' => null])->save();
    }

    /**
     * Удаляет каталог бекапа во всех хранилищах копий и на локальном диске (там файлы лежат, пока выгружаются в облака).
     * Ошибку облака не пробрасываем — запись всё равно удаляется, а потерянные файлы попадут в лог.
     */
    private function removeStoredFiles(): void
    {
        if (! $this->path) {
            return;
        }
        Storage::disk('backups')->deleteDirectory($this->path);
        foreach ($this->copies()->with('storage')->get() as $copy) {
            if ($copy->storage && $copy->storage->type !== BackupStorage::LOCAL) {
                try {
                    $copy->storage->filesystem()->deleteDirectory($this->path);
                } catch (Throwable $e) {
                    Log::warning('Не удалось удалить файлы бекапа в облаке', ['backup' => $this->id, 'storage' => $copy->storage_id, 'path' => $this->path, 'error' => $e->getMessage()]);
                }
            }
        }
        $this->copies()->delete();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** Дописывает раздел подробностей бекапа (`meta`) и сразу сохраняет. */
    public function putMeta(string $key, mixed $value): void
    {
        $this->meta = [...($this->meta ?? []), $key => $value];
        $this->save();
    }

    /** Строка журнала с отметкой времени; сохраняется сразу — чтобы ход бекапа был виден в API. */
    public function appendLog(string $message): void
    {
        $this->log = [...($this->log ?? []), ['at' => now()->toIso8601String(), 'message' => $message]];
        $this->save();
    }
}
