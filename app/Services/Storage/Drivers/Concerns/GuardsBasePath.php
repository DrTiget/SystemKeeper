<?php

namespace App\Services\Storage\Drivers\Concerns;

use App\Exceptions\Storage\StorageException;
use App\Models\StorageTarget;

trait GuardsBasePath
{
    /**
     * Работаем только внутри базовой папки цели — запись в БД не должна позволять тронуть произвольный файл.
     *
     * @throws StorageException
     */
    protected function assertInsideBase(StorageTarget $target, string $fullPath): void
    {
        $base = rtrim($target->base_path, '/').'/';
        if (! str_starts_with($fullPath, $base) || preg_match('#(^|/)\.\.(/|$)#', $fullPath) || str_contains($fullPath, "\0")) {
            throw new StorageException("Путь {$fullPath} вне базовой папки хранилища.");
        }
    }
}
