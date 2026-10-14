<?php

namespace App\Services\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\Storage;

/**
 * Файл бекапа на диске `backups`: принимает поток частями, не держа его в памяти.
 */
class BackupFile
{
    /** @var resource */
    private $handle;

    public int $size = 0;

    public function __construct(private Backup $backup, public readonly string $name)
    {
        $disk = Storage::disk('backups');
        $disk->makeDirectory($backup->path);
        $this->handle = fopen($disk->path($backup->path.'/'.$name), 'wb') ?: throw new BackupException("Не удалось создать файл {$name}.");
    }

    public function write(string $chunk): void
    {
        if (fwrite($this->handle, $chunk) !== strlen($chunk)) {
            throw new BackupException("Не удалось записать {$this->name}: нет места на диске?");
        }
        $this->size += strlen($chunk);
    }

    /** Закрывает файл и добавляет его в список файлов бекапа. */
    public function close(): void
    {
        fclose($this->handle);
        $this->backup->files = [...($this->backup->files ?? []), ['name' => $this->name, 'size' => $this->size]];
        $this->backup->size = ($this->backup->size ?? 0) + $this->size;
        $this->backup->save();
    }
}
