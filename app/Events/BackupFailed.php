<?php

namespace App\Events;

use App\Models\Backup;
use Illuminate\Foundation\Events\Dispatchable;

/** Бекап завершился ошибкой. */
class BackupFailed
{
    use Dispatchable;

    public function __construct(public readonly Backup $backup) {}
}
