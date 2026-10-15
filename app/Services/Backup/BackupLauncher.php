<?php

namespace App\Services\Backup;

use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use App\Services\System\Updater;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Запуск бекапа сервера или базы данных вне расписания — вручную или по ссылке-хуку.
 */
class BackupLauncher
{
    public function __construct(private Updater $updater) {}

    /**
     * Ставит бекап в очередь. 409 — бекап этого источника уже идёт или идёт обновление системы;
     * 422 — у сервера не выбраны каталоги.
     */
    public function start(Model $source, string $trigger, ?User $user = null): Backup
    {
        if ($source instanceof Server && $source->backupSettingsOrDefault()->paths === []) {
            throw ValidationException::withMessages(['paths' => 'Сначала выберите каталоги для бекапа.']);
        }

        $active = Backup::query()->whereMorphedTo('source', $source)->whereIn('status', [Backup::QUEUED, Backup::RUNNING])->exists();
        abort_if($active, 409, 'Бекап уже выполняется.');
        abort_if($this->updater->locked(), 409, 'Идёт обновление SystemKeeper — запустите бекап после него.');

        $backup = new Backup(['trigger' => $trigger, 'status' => Backup::QUEUED, 'user_id' => $user?->id]);
        $backup->source()->associate($source)->save();
        RunBackup::dispatch($backup);

        return $backup;
    }
}
