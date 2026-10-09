<?php

namespace App\Services\Backup;

use RuntimeException;

class BackupPurgeException extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    /** Бекап или его выгрузка ещё выполняются. */
    public static function busy(string $message): self
    {
        return new self($message, 409);
    }

    /** Не удалось удалить копию в хранилище. */
    public static function remote(string $message): self
    {
        return new self($message, 502);
    }
}
