<?php

namespace App\Jobs;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Backup;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseBackupRunner;
use App\Services\Backup\ServerBackupRunner;
use App\Services\Database\DatabaseException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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

    public function handle(ServerBackupRunner $servers, DatabaseBackupRunner $databases): void
    {
        $backup = $this->backup;
        $source = $backup->source;
        if ($source === null) {
            $backup->delete();

            return;
        }

        $backup->forceFill(['status' => Backup::RUNNING, 'started_at' => now(), 'path' => self::pathFor($backup)])->save();

        try {
            match (true) {
                $source instanceof Server => $servers->run($source, $backup),
                $source instanceof DatabaseConnection => $databases->run($source, $backup),
            };
            $backup->forceFill(['status' => Backup::SUCCESS, 'finished_at' => now()])->save();
        } catch (BackupException|DatabaseException|SshConnectionException $e) {
            $this->markFailed($e->getMessage());
        } catch (Throwable $e) {
            Log::error('Бекап завершился с ошибкой', ['backup' => $backup->id, 'exception' => $e]);
            $this->markFailed('Внутренняя ошибка: '.$e->getMessage());
        }
    }

    /** Задача упала вне handle() — например, по таймауту. */
    public function failed(?Throwable $e): void
    {
        $this->backup->refresh();
        if ($this->backup->isActive()) {
            $this->markFailed($e?->getMessage() ?: 'Бекап прерван.');
        }
    }

    private function markFailed(string $error): void
    {
        $this->backup->forceFill(['status' => Backup::FAILED, 'finished_at' => now(), 'error' => $error])->save();
    }

    /** servers/5/2026-10-14_120000-17 */
    private static function pathFor(Backup $backup): string
    {
        return $backup->source_type.'s/'.$backup->source_id.'/'.now()->setTimezone(config('backup.timezone'))->format('Y-m-d_His').'-'.$backup->id;
    }
}
