<?php

namespace App\Services\Backup;

use App\Models\BackupStorage;
use App\Services\Storage\YandexDiskAdapter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;
use Throwable;

/**
 * Копирование файлов бекапов из одного хранилища в другое. На Яндекс Диск — «по ссылке»: Диск сам скачивает файл
 * (временная ссылка S3 или подписанная ссылка SystemKeeper), до `parallel` файлов одновременно (настройка хранилища);
 * мелкие файлы и всё, что не вышло скачать по ссылке, — обычной загрузкой потоком.
 */
class StorageTransfer
{
    public function __construct(private TransferLinks $links) {}

    /**
     * Скопировать файлы; по каждому вызывается `done(null)` или `done($ошибка)`.
     *
     * @param  list<array{from: BackupStorage, fs: Filesystem, path: string, size: int|null, done: callable(?Throwable): void, progress?: callable(int): void}>  $tasks
     * @param  callable(string): void|null  $log  сообщения для журнала
     */
    public function copyMany(array $tasks, BackupStorage $to, Filesystem $toFs, ?callable $log = null): void
    {
        $adapter = $toFs instanceof FilesystemAdapter ? $toFs->getAdapter() : null;
        if (! $adapter instanceof YandexDiskAdapter || ! config('yandex_disk.fetch_by_url')) {
            foreach ($tasks as $task) {
                $this->run($task, fn () => $this->stream($task, $toFs));
            }

            return;
        }

        $client = $adapter->client();
        $parallel = max(1, min(10, (int) ($to->settings['parallel'] ?? config('yandex_disk.parallel'))));
        $timeout = (int) config('yandex_disk.transfer_timeout');
        $inFlight = [];

        while ($tasks || $inFlight) {
            // раздаём Диску новые ссылки, пока есть места
            while ($tasks && count($inFlight) < $parallel) {
                $task = array_shift($tasks);
                $url = $this->linkable($task) ? $this->publicUrl($task['from'], $task['fs'], $task['path']) : null;
                if ($url === null) {
                    $this->run($task, fn () => $this->stream($task, $toFs));

                    continue;
                }
                try {
                    $inFlight[] = ['task' => $task, 'operation' => $client->startUploadFromUrl($adapter->fullPath($task['path']), $url), 'deadline' => time() + $timeout];
                } catch (Throwable $e) {
                    $this->fallback($task, $toFs, $e, $log);
                }
            }
            if (! $inFlight) {
                continue;
            }

            usleep((int) config('yandex_disk.poll_ms') * 1000);
            foreach ($inFlight as $i => $op) {
                $task = $op['task'];
                try {
                    $status = $client->operationStatus($op['operation']);
                    if ($status === 'in-progress' && time() < $op['deadline']) {
                        continue;
                    }
                    unset($inFlight[$i]);
                    $client->finishUploadFromUrl($adapter->fullPath($task['path']), $status, $task['size']);
                    isset($task['progress']) && ($task['progress'])((int) $task['size']);
                    ($task['done'])(null);
                } catch (Throwable $e) {
                    unset($inFlight[$i]);
                    $this->fallback($task, $toFs, $e, $log);
                }
            }
        }
    }

    /** Один файл (см. copyMany). */
    public function copy(BackupStorage $from, Filesystem $fromFs, BackupStorage $to, Filesystem $toFs, string $path, ?int $size, ?callable $onProgress = null, ?callable $log = null): void
    {
        $error = null;
        $this->copyMany([['from' => $from, 'fs' => $fromFs, 'path' => $path, 'size' => $size, 'progress' => $onProgress, 'done' => function (?Throwable $e) use (&$error) {
            $error = $e;
        }]], $to, $toFs, $log);
        if ($error) {
            throw $error;
        }
    }

    /** Не вышло по ссылке — загружаем сами. */
    private function fallback(array $task, Filesystem $toFs, Throwable $e, ?callable $log): void
    {
        $log && $log('Яндекс Диск не скачал '.basename($task['path']).' по ссылке ('.mb_substr($e->getMessage(), 0, 300).') — загружаем напрямую');
        $this->run($task, fn () => $this->stream($task, $toFs));
    }

    private function run(array $task, callable $copy): void
    {
        try {
            $copy();
        } catch (Throwable $e) {
            ($task['done'])($e);

            return;
        }
        ($task['done'])(null);
    }

    /** Загрузка потоком с проверкой размера. */
    private function stream(array $task, Filesystem $toFs): void
    {
        $stream = $task['fs']->readStream($task['path']) ?: throw new RuntimeException('Нет файла '.basename($task['path'])." в «{$task['from']->name}»");
        isset($task['progress']) && ProgressStream::watch($stream, $task['progress']);
        try {
            $toFs->writeStream($task['path'], $stream);
        } finally {
            is_resource($stream) && fclose($stream);
        }
        if ($task['size'] !== null && $toFs->size($task['path']) !== $task['size']) {
            throw new RuntimeException('Размер '.basename($task['path']).' после копирования не совпал');
        }
    }

    /** Мелкие файлы быстрее загрузить самим: у скачивания по ссылке постоянная задержка (~15 с на операцию). */
    private function linkable(array $task): bool
    {
        return $task['size'] === null || $task['size'] >= (int) config('yandex_disk.fetch_min_size');
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
