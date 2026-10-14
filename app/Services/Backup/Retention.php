<?php

namespace App\Services\Backup;

use App\Models\BackupStorage;
use Illuminate\Support\Collection;

/**
 * Настройки хранения бекапов источника (сервера или базы данных).
 */
final readonly class Retention
{
    public function __construct(
        /** @var list<int> Куда сохранять; пусто — в хранилище по умолчанию */
        public array $storageIds,
        /** Сколько последних успешных бекапов хранить */
        public int $keepCount,
        /** Предельный объём всех бекапов источника, байт; null — без ограничения */
        public ?int $maxSize,
    ) {}

    /**
     * Хранилища, куда сохранять (удалённые из списка пропускаются; если не осталось ни одного — по умолчанию).
     *
     * @return Collection<int, BackupStorage>
     */
    public function storages(): Collection
    {
        $storages = $this->storageIds ? BackupStorage::query()->whereKey($this->storageIds)->orderBy('id')->get() : collect();

        return $storages->isNotEmpty() ? $storages : collect([BackupStorage::default()]);
    }
}
