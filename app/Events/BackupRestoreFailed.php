<?php

namespace App\Events;

use App\Models\Backup;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Тестовое восстановление дампа не удалось (событие `backup.restore_failed` для алертов).
 * Статус «skipped» (нет тестового сервера и т. п.) событие не вызывает.
 */
class BackupRestoreFailed
{
    use Dispatchable;

    public const NAME = 'backup.restore_failed';

    public function __construct(public readonly Backup $backup) {}
}
