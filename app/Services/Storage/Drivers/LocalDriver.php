<?php

namespace App\Services\Storage\Drivers;

use App\Exceptions\Storage\StorageException;
use App\Models\StorageTarget;
use App\Services\Storage\Drivers\Concerns\GuardsBasePath;

class LocalDriver implements StorageDriver
{
    use GuardsBasePath;

    public function __construct(private readonly StorageTarget $target) {}

    public function upload(string $localPath, string $remotePath): array
    {
        $dir = dirname($remotePath);
        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new StorageException("Не удалось создать каталог {$dir}.");
        }
        if (! copy($localPath, $remotePath)) {
            throw new StorageException("Не удалось скопировать файл в {$remotePath}.");
        }

        $expected = md5_file($localPath);
        if (md5_file($remotePath) !== $expected) {
            throw new StorageException('Контрольная сумма копии не совпала с исходной.');
        }

        return ['md5' => $expected, 'sha256' => null];
    }

    public function delete(string $remotePath): void
    {
        $this->assertInsideBase($this->target, $remotePath);

        if (is_file($remotePath) && ! unlink($remotePath)) {
            throw new StorageException("Не удалось удалить файл {$remotePath}.");
        }
    }

    public function check(): array
    {
        $path = $this->target->base_path;
        if (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path)) {
            throw new StorageException("Каталог {$path} не существует и не может быть создан.");
        }
        if (! is_writable($path)) {
            throw new StorageException("Каталог {$path} недоступен для записи.");
        }

        $total = (int) disk_total_space($path);
        $free = (int) disk_free_space($path);

        return ['total_space' => $total, 'used_space' => $total - $free, 'free_space' => $free];
    }
}
