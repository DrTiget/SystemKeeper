<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunBackup implements ShouldQueue
{
    use Queueable;

    /** Повторный запуск создал бы дубль потока, поэтому без повторов */
    public int $tries = 1;

    public int $timeout = 14400;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Backup $backup)
    {
        $this->timeout = (int) config('backup.timeout');
    }

    public function handle(BackupRunner $runner): void
    {
        if ($this->backup->status !== Backup::STATUS_PENDING) {
            return;
        }

        $runner->run($this->backup);
    }

    /**
     * Срабатывает при таймауте и падении воркера — иначе бекап навсегда остался бы «running».
     */
    public function failed(Throwable $e): void
    {
        $backup = $this->backup->fresh();

        if ($backup && $backup->isActive()) {
            app(BackupRunner::class)->fail($backup, $e);
        }
    }
}
