<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupPublisher;
use App\Services\Backup\BackupRotator;
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

    public function handle(BackupRunner $runner, BackupPublisher $publisher, BackupRotator $rotator): void
    {
        if ($this->backup->status !== Backup::STATUS_PENDING) {
            return;
        }

        $backup = $runner->run($this->backup);

        if ($backup->status === Backup::STATUS_SUCCESS) {
            $this->publish($backup, $publisher);
            $this->rotate($backup, $rotator);
        }
    }

    /**
     * Ротация сразу после бекапа; её сбой тоже не портит успешный бекап (ту же работу делает ночная команда).
     */
    private function rotate(Backup $backup, BackupRotator $rotator): void
    {
        try {
            $rotator->rotate($backup->job);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Сбой постановки выгрузки не должен делать успешный бекап неуспешным — пишем его в лог бекапа.
     */
    private function publish(Backup $backup, BackupPublisher $publisher): void
    {
        try {
            $publisher->publish($backup);
        } catch (Throwable $e) {
            $note = 'Не удалось поставить выгрузку в хранилища: '.$e->getMessage();
            $backup->forceFill(['log' => trim(($backup->log ? $backup->log."\n" : '').$note)])->save();
            report($e);
        }
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
