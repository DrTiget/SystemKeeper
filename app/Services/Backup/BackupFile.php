<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Support\Bytes;
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

    private string $fullPath;

    /** Байт записано с последней проверки свободного места */
    private int $sinceSpaceCheck = self::SPACE_CHECK_EVERY; // первая проверка — на первом же куске

    /** Проверяем свободное место не чаще чем раз в столько байт */
    private const SPACE_CHECK_EVERY = 64 * 1024 * 1024;

    public function __construct(private Backup $backup, public readonly string $name)
    {
        $disk = Storage::disk('backups');
        $disk->makeDirectory($backup->path);
        $this->hash = hash_init('sha256');
        if (str_ends_with($name, '.gz')) {
            $this->inflate = inflate_init(ZLIB_ENCODING_GZIP) ?: null;
            $this->uncompressedSize = 0;
        }
        $this->fullPath = $disk->path($backup->path.'/'.$name);
        $this->handle = fopen($this->fullPath, 'wb') ?: throw new BackupException("Не удалось создать файл {$name}.");
    }

    public function write(string $chunk): void
    {
        $this->ensureFreeSpace(strlen($chunk));
        // предупреждение PHP не должно превращаться в ErrorException: ошибку оформляем сами и сразу чистим за собой
        $reason = '';
        set_error_handler(function (int $no, string $message) use (&$reason) {
            $reason = $message;

            return true;
        });
        try {
            $written = fwrite($this->handle, $chunk);
        } finally {
            restore_error_handler();
        }
        if ($written !== strlen($chunk)) {
            $this->abort();
            throw new BackupException("Не удалось записать {$this->name} на диск SystemKeeper"
                .(str_contains($reason, 'errno=28') || str_contains($reason, 'No space') ? ': закончилось место на диске.' : ($reason !== '' ? ": {$reason}" : '.')));
        }
        hash_update($this->hash, $chunk);
        $this->countUncompressed($chunk);
        $this->size += strlen($chunk);
    }

    /**
     * Не даём забить диск до нуля: на нём же MySQL и журналы, а при полном диске MySQL
     * останавливает запись и задача бекапа не может даже отметить себя неудачной.
     */
    private function ensureFreeSpace(int $incoming): void
    {
        $this->sinceSpaceCheck += $incoming;
        if ($this->sinceSpaceCheck < self::SPACE_CHECK_EVERY) {
            return;
        }
        $this->sinceSpaceCheck = 0;
        $free = @disk_free_space(dirname($this->fullPath));
        $reserve = (int) config('backup.write_reserve');
        if ($free !== false && $free - $incoming < $reserve) {
            $this->abort();
            throw new BackupException('Бекап остановлен: на диске SystemKeeper осталось '.Bytes::format((int) $free)
                .' — меньше резерва '.Bytes::format($reserve).". Записано {$this->name}: ".Bytes::format($this->size).'.');
        }
    }

    /** Прерывает запись: закрывает и удаляет неполный файл, чтобы сразу освободить место. */
    public function abort(): void
    {
        if (is_resource($this->handle)) {
            @fclose($this->handle);
        }
        @unlink($this->fullPath);
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
