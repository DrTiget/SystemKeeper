<?php

namespace App\Services\Storage;

use App\Exceptions\Storage\StorageException;
use App\Jobs\UploadFileToStorageTargets;
use App\Models\StorageCopy;
use App\Models\StorageTarget;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class StorageUploader implements StorageUploaderInterface
{
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
                $copy->fill([
                    'md5' => $this->put($target, $localPath, $full),
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

        match ($target->type) {
            StorageTarget::TYPE_YANDEX_DISK => (new YandexDiskClient((string) $target->token))->delete($copy->remote_path),
            StorageTarget::TYPE_LOCAL => $this->deleteLocal($target, $copy->remote_path),
            default => throw new StorageException("Неизвестный тип хранилища: {$target->type}"),
        };
    }

    public function dispatch(string $localPath, iterable $targets, string $remotePath, ?Model $uploadable = null): void
    {
        $ids = [];
        foreach ($targets as $target) {
            $ids[] = $target instanceof StorageTarget ? $target->id : (int) $target;
        }

        UploadFileToStorageTargets::dispatch($localPath, $ids, $remotePath, $uploadable?->getMorphClass(), $uploadable?->getKey());
    }

    /** @return string md5 загруженного файла */
    private function put(StorageTarget $target, string $localPath, string $fullPath): string
    {
        return match ($target->type) {
            StorageTarget::TYPE_YANDEX_DISK => (new YandexDiskClient((string) $target->token))->upload($localPath, $fullPath),
            StorageTarget::TYPE_LOCAL => $this->putLocal($localPath, $fullPath),
            default => throw new StorageException("Неизвестный тип хранилища: {$target->type}"),
        };
    }

    private function deleteLocal(StorageTarget $target, string $fullPath): void
    {
        // Удаляем только внутри базовой папки цели — запись в БД не должна позволять стереть произвольный файл
        $base = rtrim($target->base_path, '/').'/';
        if (! str_starts_with($fullPath, $base) || str_contains($fullPath, '..')) {
            throw new StorageException("Путь {$fullPath} вне базовой папки хранилища.");
        }
        if (is_file($fullPath) && ! unlink($fullPath)) {
            throw new StorageException("Не удалось удалить файл {$fullPath}.");
        }
    }

    private function putLocal(string $localPath, string $fullPath): string
    {
        $dir = dirname($fullPath);
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new StorageException("Не удалось создать каталог {$dir}.");
        }
        if (! copy($localPath, $fullPath)) {
            throw new StorageException("Не удалось скопировать файл в {$fullPath}.");
        }

        $expected = md5_file($localPath);
        if (md5_file($fullPath) !== $expected) {
            throw new StorageException('Контрольная сумма копии не совпала с исходной.');
        }

        return $expected;
    }
}
