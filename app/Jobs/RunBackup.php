<?php

namespace App\Jobs;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Backup;
use App\Models\BackupCopy;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupManifest;
use App\Services\Backup\BackupRetention;
use App\Services\Backup\DatabaseBackupRunner;
use App\Services\Backup\ServerBackupRunner;
use App\Services\Database\DatabaseException;
use App\Services\System\Updater;
use App\Support\Bytes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Выполняет бекап сервера или базы данных; итог и журнал — в модели Backup.
 */
class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 14400;

    public bool $failOnTimeout = true;

    public function __construct(public Backup $backup)
    {
        $this->onQueue('backups');
    }

    public function handle(ServerBackupRunner $servers, DatabaseBackupRunner $databases, BackupRetention $retention, BackupManifest $manifest, Updater $updater): void
    {
        // идёт обновление — сервисы вот-вот перезапустятся; бекап остаётся в очереди и начнётся после
        if ($updater->locked()) {
            self::dispatch($this->backup)->delay(60);

            return;
        }

        $backup = $this->backup;
        $source = $backup->source;
        if ($source === null) {
            $backup->delete();

            return;
        }

        $storages = $source->retention()->storages();
        $backup->forceFill(['status' => Backup::RUNNING, 'started_at' => now(), 'path' => self::pathFor($backup)])->save();

        try {
            // Файлы сначала пишутся на локальный диск (в облако — уже готовыми), поэтому место нужно и там
            $local = BackupStorage::default();
            $free = $local->disk()['free'] ?? null;
            $min = (int) config('backup.min_free_space');
            if ($free !== null && $free < $min) {
                throw new BackupException('В «'.$local->name.'» свободно '.Bytes::format($free).' — меньше минимума '.Bytes::format($min).'.');
            }

            match (true) {
                $source instanceof Server => $servers->run($source, $backup),
                $source instanceof DatabaseConnection => $databases->run($source, $backup),
            };
            $this->distribute($storages, $manifest);
            $backup->forceFill(['status' => Backup::SUCCESS, 'finished_at' => now()])->save();
        } catch (BackupException|DatabaseException|SshConnectionException $e) {
            $this->markFailed($e->getMessage());
        } catch (Throwable $e) {
            Log::error('Бекап завершился с ошибкой', ['backup' => $backup->id, 'exception' => $e]);
            $this->markFailed('Внутренняя ошибка: '.$e->getMessage());
        }

        // Старые бекапы чистим и после неудачи — чтобы не копились записи
        foreach ($retention->apply($source) as $message) {
            $backup->appendLog($message);
        }

        // Оглавление в каждом месте, где лежат (или должны лежать) бекапы источника
        foreach (self::storagesOf($source, $storages) as $storage) {
            if ($error = $manifest->sync($storage, $source)) {
                $backup->appendLog($error);
            }
        }
    }

    /**
     * Выбранные места и те, где ещё остались копии бекапов источника.
     *
     * @param  Collection<int, BackupStorage>  $selected
     * @return Collection<int, BackupStorage>
     */
    public static function storagesOf(Server|DatabaseConnection $source, Collection $selected): Collection
    {
        $ids = BackupCopy::query()->whereIn('backup_id', $source->backups()->select('id'))->distinct()->pluck('storage_id');

        return BackupStorage::query()->whereKey($selected->pluck('id')->merge($ids)->unique())->orderBy('id')->get();
    }

    /**
     * Раскладывает готовые файлы по выбранным хранилищам: на диске SystemKeeper они уже лежат, в облака — загружаются.
     * Неудача в части мест — предупреждение в журнале; если не удалось никуда — ошибка бекапа.
     *
     * @param  Collection<int, BackupStorage>  $storages
     */
    private function distribute(Collection $storages, BackupManifest $manifest): void
    {
        $backup = $this->backup;
        $local = Storage::disk('backups');
        $errors = [];

        foreach ($storages as $storage) {
            if ($storage->type === BackupStorage::LOCAL) {
                $manifest->writeBackupInfo($local, $backup);
                $backup->copies()->create(['storage_id' => $storage->id, 'status' => BackupCopy::SUCCESS]);

                continue;
            }
            $backup->appendLog("Загружаем в «{$storage->name}»");
            try {
                $fs = $storage->filesystem();
                foreach ($backup->files ?? [] as $file) {
                    $stream = $local->readStream($backup->path.'/'.$file['name']);
                    $fs->writeStream($backup->path.'/'.$file['name'], $stream);
                    is_resource($stream) && fclose($stream);
                }
                $manifest->writeBackupInfo($fs, $backup);
                $backup->copies()->create(['storage_id' => $storage->id, 'status' => BackupCopy::SUCCESS]);
                $backup->appendLog("Загружено в «{$storage->name}»");
            } catch (Throwable $e) {
                $error = "Не удалось загрузить в «{$storage->name}»: ".mb_substr($e->getMessage(), 0, 1000);
                $backup->copies()->create(['storage_id' => $storage->id, 'status' => BackupCopy::FAILED, 'error' => $error]);
                $backup->appendLog($error);
                $errors[] = $error;
            }
        }

        // Локальная копия нужна, только если диск SystemKeeper среди выбранных
        if (! $storages->contains(fn (BackupStorage $s) => $s->type === BackupStorage::LOCAL)) {
            $local->deleteDirectory($backup->path);
        }
        if (count($errors) === $storages->count()) {
            throw new BackupException(implode("\n", $errors));
        }
    }

    /** Задача упала вне handle() — например, по таймауту. */
    public function failed(?Throwable $e): void
    {
        $this->backup->refresh();
        if ($this->backup->isActive()) {
            $this->markFailed(match (true) {
                // задачу забрали у воркера, который упал или был перезапущен посреди бекапа
                $e instanceof MaxAttemptsExceededException => 'Бекап прерван: воркер бекапов был остановлен или перезапущен во время работы. Запустите бекап заново.',
                $e instanceof TimeoutExceededException => 'Бекап не уложился в '.intdiv($this->timeout, 3600).' ч и был остановлен.',
                default => $e?->getMessage() ?: 'Бекап прерван.',
            });
        }
    }

    /** Файлы неудачного бекапа неполные — удаляем их, запись с журналом остаётся. */
    private function markFailed(string $error): void
    {
        $this->backup->forceFill(['status' => Backup::FAILED, 'finished_at' => now(), 'error' => $error])->save();
        $this->backup->deleteFiles();
    }

    /** servers/5/2026-10-14_120000-17 */
    private static function pathFor(Backup $backup): string
    {
        return $backup->source_type.'s/'.$backup->source_id.'/'.now()->setTimezone(config('backup.timezone'))->format('Y-m-d_His').'-'.$backup->id;
    }
}
