<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\StorageCopy;
use App\Models\StorageTarget;
use App\Services\Storage\StorageUploaderInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Ставит в очередь выгрузку готового бекапа в хранилища, выбранные в задании (storage_target_ids).
 */
class BackupPublisher
{
    public function __construct(private readonly StorageUploaderInterface $uploader) {}

    /**
     * @return int число хранилищ, в которые поставлена выгрузка
     */
    public function publish(Backup $backup): int
    {
        $backup->loadMissing('job');
        $ids = $backup->job->storage_target_ids ?? [];

        if ($backup->status !== Backup::STATUS_SUCCESS || $backup->file_path === null || $ids === []) {
            return 0;
        }

        $targets = StorageTarget::query()->whereIn('id', $ids)->where('enabled', true)->get();
        if ($targets->isEmpty()) {
            return 0;
        }

        $remotePath = $backup->file_path; // {server}/{job}/{файл} — относительно base_path хранилища

        // Строки «pending» заводим сразу, чтобы API показывал выгрузку до того, как её подхватит воркер
        foreach ($targets as $target) {
            StorageCopy::query()->firstOrCreate([
                'uploadable_type' => $backup->getMorphClass(),
                'uploadable_id' => $backup->getKey(),
                'storage_target_id' => $target->id,
                'remote_path' => $target->resolvePath($remotePath),
            ], ['status' => StorageCopy::STATUS_PENDING]);
        }

        $this->uploader->dispatch(Storage::disk(config('backup.disk'))->path($backup->file_path), $targets, $remotePath, $backup);

        return $targets->count();
    }
}
