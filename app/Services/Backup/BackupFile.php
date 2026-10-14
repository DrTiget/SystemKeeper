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

    /** SHA-256 считается на лету — файл не перечитывается */
    private \HashContext $hash;

    /** Для .gz — объём после распаковки, тоже на лету; null — не gzip или поток повреждён */
    public ?int $uncompressedSize = null;

    private ?\InflateContext $inflate = null;

    public function __construct(private Backup $backup, public readonly string $name)
    {
        $disk = Storage::disk('backups');
        $disk->makeDirectory($backup->path);
        $this->hash = hash_init('sha256');
        if (str_ends_with($name, '.gz')) {
            $this->inflate = inflate_init(ZLIB_ENCODING_GZIP) ?: null;
            $this->uncompressedSize = 0;
        }
        $this->handle = fopen($disk->path($backup->path.'/'.$name), 'wb') ?: throw new BackupException("Не удалось создать файл {$name}.");
    }

    public function write(string $chunk): void
    {
        if (fwrite($this->handle, $chunk) !== strlen($chunk)) {
            throw new BackupException("Не удалось записать {$this->name}: нет места на диске?");
        }
        hash_update($this->hash, $chunk);
        $this->countUncompressed($chunk);
        $this->size += strlen($chunk);
    }

    /**
     * Считает распакованный объём. Вход режем на куски по 1 КБ: deflate сжимает максимум ~1000:1,
     * так что один кусок распаковывается не больше чем в ~1 МБ памяти.
     */
    private function countUncompressed(string $chunk): void
    {
        if ($this->inflate === null) {
            return;
        }
        foreach (str_split($chunk, 1024) as $piece) {
            $out = @inflate_add($this->inflate, $piece, ZLIB_SYNC_FLUSH);
            if ($out === false) {
                $this->inflate = null;
                $this->uncompressedSize = null;

                return;
            }
            $this->uncompressedSize += strlen($out);
        }
    }

    /** Закрывает файл и добавляет его в список файлов бекапа. */
    public function close(): void
    {
        fclose($this->handle);
        $this->backup->files = [...($this->backup->files ?? []), ['name' => $this->name, 'size' => $this->size, 'sha256' => hash_final($this->hash), ...($this->uncompressedSize !== null ? ['uncompressed_size' => $this->uncompressedSize] : [])]];
        $this->backup->size = ($this->backup->size ?? 0) + $this->size;
        $this->backup->save();
    }
}
