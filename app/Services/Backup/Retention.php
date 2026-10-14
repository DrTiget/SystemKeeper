<?php

namespace App\Services\Backup;

use App\Models\BackupStorage;

/**
 * Настройки хранения бекапов источника (сервера или базы данных).
 */
final readonly class Retention
{
    public function __construct(
        public ?int $storageId,
        /** Сколько последних успешных бекапов хранить */
        public int $keepCount,
        /** Предельный объём всех бекапов источника, байт; null — без ограничения */
        public ?int $maxSize,
    ) {}

    public function storage(): BackupStorage
    {
        return ($this->storageId ? BackupStorage::find($this->storageId) : null) ?? BackupStorage::default();
    }
}
