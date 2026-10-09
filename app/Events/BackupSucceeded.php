<?php

namespace App\Events;

use App\Models\Backup;
use Illuminate\Foundation\Events\Dispatchable;

/** Бекап успешно завершён. */
class BackupSucceeded
{
    use Dispatchable;

    public function __construct(public readonly Backup $backup) {}
}
