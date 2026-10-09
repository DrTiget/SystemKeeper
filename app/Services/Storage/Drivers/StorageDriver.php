<?php

namespace App\Services\Storage\Drivers;

use App\Exceptions\Storage\StorageException;

/**
 * Работа с одним хранилищем. Пути — полные (base_path цели + относительный путь), как в StorageCopy::remote_path.
 */
interface StorageDriver
{
    /**
     * Потоково загружает файл и проверяет целостность на стороне хранилища.
     *
     * @return array{md5: string|null, sha256: string|null} подтверждённые контрольные суммы
     *
     * @throws StorageException
     */
    public function upload(string $localPath, string $remotePath): array;

    /**
     * Удаляет файл; отсутствие файла — не ошибка.
     *
     * @throws StorageException
     */
    public function delete(string $remotePath): void;

    /**
     * Проверка доступности и прав записи. Ключи места — null, если хранилище его не сообщает.
     *
     * @return array{total_space: int|null, used_space: int|null, free_space: int|null}
     *
     * @throws StorageException
     */
    public function check(): array;
}
