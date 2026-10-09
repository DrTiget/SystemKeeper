<?php

namespace App\Services\Storage;

use App\Models\StorageCopy;
use App\Models\StorageTarget;
use Illuminate\Database\Eloquent\Model;

/**
 * Загрузка локального файла на набор хранилищ. Вызывается модулем бекапов после создания бекапа.
 */
interface StorageUploaderInterface
{
    /**
     * Синхронно загружает файл на каждую цель; сбой одной цели не прерывает остальные.
     * Уже загруженные копии (та же цель, путь и владелец) пропускаются.
     *
     * @param  iterable<StorageTarget>  $targets
     * @return list<StorageCopy> по копии на каждую цель, со статусом uploaded|failed
     */
    public function upload(string $localPath, iterable $targets, string $remotePath, ?Model $uploadable = null): array;

    /**
     * То же самое в очереди (Job UploadFileToStorageTargets).
     *
     * @param  iterable<StorageTarget|int>  $targets
     */
    public function dispatch(string $localPath, iterable $targets, string $remotePath, ?Model $uploadable = null): void;
}
