<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\BackupCopy;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Models\StorageSync;
use App\Services\Backup\BackupManifest;
use App\Services\Backup\StorageTransfer;
use App\Services\System\Updater;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Копирует в хранилище все успешные бекапы, которых в нём ещё нет, из любого места, где они уже лежат.
 * Ошибка одного бекапа не останавливает остальные: он отмечается неудачной копией, повторный запуск докопирует.
 */
class SyncStorage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 14400;

    public bool $failOnTimeout = true;

    public function __construct(public StorageSync $sync)
    {
        $this->onQueue('backups');
    }

    public function handle(BackupManifest $manifest, Updater $updater): void
    {
        // идёт обновление — начнём после него
        if ($updater->locked()) {
            self::dispatch($this->sync)->delay(60);

            return;
        }

        $sync = $this->sync;
        $target = $sync->storage;
        $backups = $target->missingBackups()->with('copies.storage', 'source')->orderBy('backups.id')->get();

        $sync->update([
            'status' => StorageSync::RUNNING, 'started_at' => now(),
            'total' => $backups->count(), 'bytes_total' => (int) $backups->sum('size'),
        ]);

        $to = $target->filesystem();
        $sources = [];
        $errors = [];

        foreach ($backups as $backup) {
            $sync->update(['current' => $backup->path]);
            $base = $sync->bytes_done;
            try {
                $this->copy($backup, $target, $to, $manifest, fn (int $bytes) => $sync->update(['bytes_done' => $base + min($bytes, (int) $backup->size)]));
                $sync->increment('done');
                $sources[$backup->source_type.':'.$backup->source_id] = $backup->source;
            } catch (Throwable $e) {
                // бекап удалили, пока шла синхронизация (очистка старых) — копировать уже нечего
                if (! Backup::whereKey($backup->id)->exists()) {
                    $sync->decrement('total');
                    $sync->update(['bytes_done' => $base, 'bytes_total' => max(0, $sync->bytes_total - (int) $backup->size)]);

                    continue;
                }
                $error = mb_substr($e->getMessage(), 0, 1000);
                $backup->copies()->updateOrCreate(['storage_id' => $target->id], ['status' => BackupCopy::FAILED, 'error' => $error]);
                $sync->increment('failed');
                $errors[] = "{$backup->path}: {$error}";
            }
            $sync->update(['bytes_done' => $base + (int) $backup->size]);
        }

        foreach (array_filter($sources) as $source) {
            if ($error = $manifest->sync($target, $source)) {
                $errors[] = $error;
            }
            if ($sync->add_to_sources) {
                $this->addToSource($source, $target);
            }
        }

        $sync->update([
            'status' => $sync->failed > 0 && $sync->done === 0 ? StorageSync::FAILED : StorageSync::SUCCESS,
            'current' => null,
            'error' => $errors ? mb_substr(implode("\n", array_slice($errors, 0, 20)), 0, 10000) : null,
            'finished_at' => now(),
        ]);
    }

    public function failed(?Throwable $e): void
    {
        $this->sync->update([
            'status' => StorageSync::FAILED, 'current' => null, 'finished_at' => now(),
            'error' => mb_substr((string) $e?->getMessage(), 0, 10000) ?: 'Синхронизация прервана',
        ]);
    }

    /** @param callable(int): void $onProgress сколько байт бекапа уже скопировано */
    private function copy(Backup $backup, BackupStorage $target, Filesystem $to, BackupManifest $manifest, callable $onProgress): void
    {
        $from = $backup->copies->where('status', BackupCopy::SUCCESS)->where('storage_id', '!=', $target->id)->map->storage->filter()
            ->sortBy(fn (BackupStorage $s) => $s->type === BackupStorage::LOCAL ? 0 : 1)->first()
            ?? throw new RuntimeException('Бекап не найден ни в одном хранилище');
        $fs = $from->filesystem();

        $copied = 0;
        foreach ($backup->files ?? [] as $file) {
            $path = $backup->path.'/'.$file['name'];
            $before = $copied;
            app(StorageTransfer::class)->copy($from, $fs, $target, $to, $path, isset($file['size']) ? (int) $file['size'] : null, function (int $bytes) use (&$copied, $before, $onProgress) {
                $copied = $before + $bytes;
                $onProgress($copied);
            });
            if (isset($file['size']) && $to->size($path) !== (int) $file['size']) {
                throw new RuntimeException("Размер {$file['name']} после копирования не совпал");
            }
        }

        $manifest->writeBackupInfo($to, $backup);
        $backup->copies()->updateOrCreate(['storage_id' => $target->id], ['status' => BackupCopy::SUCCESS, 'error' => null]);
    }

    /** Новые бекапы источника тоже будут сохраняться в это хранилище. */
    private function addToSource(Server|DatabaseConnection $source, BackupStorage $target): void
    {
        $ids = $source->retention()->storages()->pluck('id')->push($target->id)->unique()->sort()->values()->all();

        if ($source instanceof DatabaseConnection) {
            $source->update(['storage_ids' => $ids]);
        } elseif ($source->backupSettings) {
            $source->backupSettings->update(['storage_ids' => $ids]);
        }
    }
}
