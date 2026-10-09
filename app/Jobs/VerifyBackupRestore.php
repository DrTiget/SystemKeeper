<?php

namespace App\Jobs;

use App\Events\BackupRestoreFailed;
use App\Models\Backup;
use App\Services\Backup\RestoreVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class VerifyBackupRestore implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Backup $backup)
    {
        // Запас сверх таймаутов отдельных команд: их несколько (создание, восстановление, запросы, удаление)
        $this->timeout = (int) config('backup.restore.timeout') + 600;
    }

    public function handle(RestoreVerifier $verifier): void
    {
        if (! in_array($this->backup->restore_status, [Backup::RESTORE_PENDING, null], true)) {
            return;
        }

        $verifier->verify($this->backup);
    }

    /** Таймаут или падение воркера: иначе проверка осталась бы «running» навсегда. */
    public function failed(Throwable $e): void
    {
        $backup = $this->backup->fresh();

        if ($backup && in_array($backup->restore_status, [Backup::RESTORE_PENDING, Backup::RESTORE_RUNNING], true)) {
            $backup->forceFill([
                'restore_status' => Backup::RESTORE_FAILED,
                'restore_checked_at' => now(),
                'restore_log' => 'Проверка не завершилась: '.mb_substr($e->getMessage(), 0, 500),
            ])->save();
            event(new BackupRestoreFailed($backup));
        }
    }
}
