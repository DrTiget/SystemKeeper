<?php

namespace App\Services\Storage;

use App\Jobs\UploadFileToStorageTargets;
use App\Models\StorageCopy;
use App\Models\StorageTarget;
use App\Services\Storage\Drivers\StorageDriverFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class StorageUploader implements StorageUploaderInterface
{
    private readonly StorageDriverFactory $drivers;

    public function __construct(?StorageDriverFactory $drivers = null)
    {
        $this->drivers = $drivers ?? new StorageDriverFactory;
    }

    public function upload(string $localPath, iterable $targets, string $remotePath, ?Model $uploadable = null): array
    {
        $copies = [];

        foreach ($targets as $target) {
            $full = $target->resolvePath($remotePath);
            $copy = StorageCopy::firstOrNew([
                'uploadable_type' => $uploadable?->getMorphClass(),
                'uploadable_id' => $uploadable?->getKey(),
                'storage_target_id' => $target->id,
                'remote_path' => $full,
            ]);

            $copies[] = $copy;
            if ($copy->status === StorageCopy::STATUS_UPLOADED) {
                continue;
            }

            $copy->fill(['status' => StorageCopy::STATUS_UPLOADING, 'error' => null, 'attempts' => $copy->attempts + 1])->save();

            try {
                $checksums = $this->drivers->make($target)->upload($localPath, $full);
                $copy->fill([
                    'md5' => $checksums['md5'],
                    'sha256' => $checksums['sha256'],
                    'size' => filesize($localPath),
                    'status' => StorageCopy::STATUS_UPLOADED,
                    'uploaded_at' => now(),
                ]);
            } catch (Throwable $e) {
                $copy->fill(['status' => StorageCopy::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            }
            $copy->save();
        }

        return $copies;
    }

    public function deleteCopy(StorageCopy $copy): void
    {
        $target = $copy->target;
        if ($target === null) {
            return;
        }

        $this->drivers->make($target)->delete($copy->remote_path);
    }

    public function dispatch(string $localPath, iterable $targets, string $remotePath, ?Model $uploadable = null): void
    {
        $ids = [];
        foreach ($targets as $target) {
            $ids[] = $target instanceof StorageTarget ? $target->id : (int) $target;
        }

        UploadFileToStorageTargets::dispatch($localPath, $ids, $remotePath, $uploadable?->getMorphClass(), $uploadable?->getKey());
    }
}
