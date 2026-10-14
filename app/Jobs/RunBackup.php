<?php

namespace App\Jobs;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Backup;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupRetention;
use App\Services\Backup\DatabaseBackupRunner;
use App\Services\Backup\ServerBackupRunner;
use App\Services\Database\DatabaseException;
use App\Support\Bytes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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

    public function handle(ServerBackupRunner $servers, DatabaseBackupRunner $databases, BackupRetention $retention): void
    {
        $backup = $this->backup;
        $source = $backup->source;
        if ($source === null) {
            $backup->delete();

            return;
        }

        $storage = $source->retention()->storage();
        $backup->forceFill(['status' => Backup::RUNNING, 'started_at' => now(), 'storage_id' => $storage->id, 'path' => self::pathFor($backup)])->save();

        try {
            // Файлы сначала пишутся на локальный диск (в облако — уже готовыми), поэтому место нужно и там
            $free = BackupStorage::default()->disk()['free'] ?? null;
            $min = (int) config('backup.min_free_space');
            if ($free !== null && $free < $min) {
                throw new BackupException('В «'.$storage->name.'» свободно '.Bytes::format($free).' — меньше минимума '.Bytes::format($min).'.');
            }

            match (true) {
                $source instanceof Server => $servers->run($source, $backup),
                $source instanceof DatabaseConnection => $databases->run($source, $backup),
            };
            if ($storage->type !== BackupStorage::LOCAL) {
                $this->upload($storage);
            }
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
    }

    /** Выгружает готовые файлы с локального диска в облачное хранилище и удаляет локальную копию. */
    private function upload(BackupStorage $storage): void
    {
        $backup = $this->backup;
        $local = Storage::disk('backups');
        $backup->appendLog("Загружаем в «{$storage->name}»");
        try {
            $fs = $storage->filesystem();
            foreach ($backup->files ?? [] as $file) {
                $stream = $local->readStream($backup->path.'/'.$file['name']);
                $fs->writeStream($backup->path.'/'.$file['name'], $stream);
                is_resource($stream) && fclose($stream);
            }
        } catch (Throwable $e) {
            throw new BackupException("Не удалось загрузить в «{$storage->name}»: ".$e->getMessage(), previous: $e);
        }
        $local->deleteDirectory($backup->path);
        $backup->appendLog('Загружено');
    }

    /** Задача упала вне handle() — например, по таймауту. */
    public function failed(?Throwable $e): void
    {
        $this->backup->refresh();
        if ($this->backup->isActive()) {
            $this->markFailed($e?->getMessage() ?: 'Бекап прерван.');
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
