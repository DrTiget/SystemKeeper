<?php

namespace App\Services\Alerts;

use App\Events\BackupFailed;
use App\Events\BackupSucceeded;
use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Events\SslExpiring;
use App\Events\StorageCopyFailed;
use Throwable;

/** Превращает события приложения в алерты. Сбой алертов не должен ломать основной процесс. */
class AlertEventListener
{
    public function __construct(private readonly AlertDispatcher $dispatcher, private readonly AlertMessageFactory $messages) {}

    public function incidentOpened(IncidentOpened $e): void
    {
        $this->send(fn () => $this->messages->incidentOpened($e->incident->loadMissing('monitor')));
    }

    public function incidentResolved(IncidentResolved $e): void
    {
        $this->send(fn () => $this->messages->incidentResolved($e->incident->loadMissing('monitor')));
    }

    public function backupFailed(BackupFailed $e): void
    {
        $this->send(fn () => $this->messages->backupFailed($e->backup->loadMissing('job')));
    }

    public function backupSucceeded(BackupSucceeded $e): void
    {
        $this->send(fn () => $this->messages->backupSucceeded($e->backup->loadMissing('job')));
    }

    public function storageCopyFailed(StorageCopyFailed $e): void
    {
        $this->send(fn () => $this->messages->storageCopyFailed($e->copy->loadMissing('target')));
    }

    public function sslExpiring(SslExpiring $e): void
    {
        $this->send(fn () => $this->messages->sslExpiring($e->monitor, $e->daysLeft));
    }

    private function send(callable $message): void
    {
        try {
            $this->dispatcher->dispatch($message());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
