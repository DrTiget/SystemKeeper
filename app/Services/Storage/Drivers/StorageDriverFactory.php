<?php

namespace App\Services\Storage\Drivers;

use App\Exceptions\Storage\StorageException;
use App\Models\StorageTarget;
use App\Services\Ssh\SshConnectorInterface;

class StorageDriverFactory
{
    public function make(StorageTarget $target): StorageDriver
    {
        return match ($target->type) {
            StorageTarget::TYPE_LOCAL => new LocalDriver($target),
            StorageTarget::TYPE_YANDEX_DISK => new YandexDiskDriver($target),
            StorageTarget::TYPE_S3 => new S3Driver($target),
            StorageTarget::TYPE_SFTP => new SftpDriver($target, app(SshConnectorInterface::class)),
            default => throw new StorageException("Неизвестный тип хранилища: {$target->type}"),
        };
    }
}
