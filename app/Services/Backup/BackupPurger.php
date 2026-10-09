<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\StorageCopy;
use App\Services\Storage\StorageUploaderInterface;
use Throwable;

/**
 * Полностью удаляет бекап: копии в хранилищах, локальный файл и запись.
 */
class BackupPurger
{
    public function __construct(private readonly StorageUploaderInterface $uploader) {}

    /**
     * Если копию в хранилище удалить не удалось, бекап остаётся целиком (локальный файл тоже) —
     * повторить можно позже. Копии, удалённые до сбоя, из учёта исчезают.
     *
     * @param  bool  $remote  false — удалить только локально, файлы в хранилищах оставить
     *
     * @throws BackupPurgeException
     */
    public function purge(Backup $backup, bool $remote = true): void
    {
        if ($backup->isActive()) {
            throw BackupPurgeException::busy("Бекап #{$backup->id} ещё выполняется.");
        }
        if ($backup->hasActiveCopies()) {
            throw BackupPurgeException::busy("Бекап #{$backup->id}: выгрузка копий в хранилища ещё не завершена.");
        }

        if ($remote) {
            foreach ($backup->copies()->with('target')->get() as $copy) {
                /** @var StorageCopy $copy */
                try {
                    $this->uploader->deleteCopy($copy);
                } catch (Throwable $e) {
                    $name = $copy->target?->name ?? "#{$copy->storage_target_id}";
                    throw BackupPurgeException::remote("Бекап #{$backup->id}: не удалось удалить копию в хранилище «{$name}»: {$e->getMessage()}");
                }
                $copy->delete();
            }
        }

        $backup->delete();
    }
}
