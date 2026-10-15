<?php

namespace App\Services\Backup;

use App\Models\BackupStorage;
use App\Services\Storage\YandexDiskAdapter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;
use Throwable;

/**
 * Копирование файла бекапа из одного хранилища в другое. На Яндекс Диск — «по ссылке»: Диск сам скачивает файл
 * (временная ссылка S3 или подписанная ссылка SystemKeeper), а если не вышло — обычной загрузкой потоком.
 */
class StorageTransfer
{
    public function __construct(private TransferLinks $links) {}

    /**
     * @param  callable(int): void|null  $onProgress  сколько байт файла уже скопировано
     * @param  callable(string): void|null  $log  сообщения для журнала бекапа
     */
    public function copy(BackupStorage $from, Filesystem $fromFs, BackupStorage $to, Filesystem $toFs, string $path, ?int $size, ?callable $onProgress = null, ?callable $log = null): void
    {
        $adapter = $toFs instanceof FilesystemAdapter ? $toFs->getAdapter() : null;
        // мелкие файлы быстрее загрузить самим: у скачивания по ссылке есть постоянная задержка (~15 с на операцию)
        $big = $size === null || $size >= (int) config('yandex_disk.fetch_min_size');
        if ($adapter instanceof YandexDiskAdapter && config('yandex_disk.fetch_by_url') && $big) {
            $url = $this->publicUrl($from, $fromFs, $path);
            if ($url !== null) {
                try {
                    $adapter->client()->uploadFromUrl($adapter->fullPath($path), $url, $size);
                    $onProgress && $onProgress((int) $size);

                    return;
                } catch (Throwable $e) {
                    $log && $log('Яндекс Диск не скачал '.basename($path).' по ссылке ('.mb_substr($e->getMessage(), 0, 300).') — загружаем напрямую');
                }
            }
        }

        $stream = $fromFs->readStream($path) ?: throw new RuntimeException('Нет файла '.basename($path)." в «{$from->name}»");
        $onProgress && ProgressStream::watch($stream, $onProgress);
        try {
            $toFs->writeStream($path, $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }
    }

    /** Ссылка, по которой файл можно скачать из интернета: временная ссылка S3 или подписанная ссылка SystemKeeper. */
    private function publicUrl(BackupStorage $from, Filesystem $fromFs, string $path): ?string
    {
        if ($from->type === BackupStorage::S3 && $fromFs instanceof FilesystemAdapter) {
            try {
                return $fromFs->temporaryUrl($path, now()->addMinutes((int) config('systemkeeper.transfer_link_ttl')));
            } catch (Throwable) {
                // не поддерживается — отдадим через SystemKeeper
            }
        }

        return $this->links->for($from, $path);
    }
}
