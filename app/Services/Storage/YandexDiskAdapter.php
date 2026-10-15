<?php

namespace App\Services\Storage;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Throwable;

/**
 * Яндекс Диск как файловая система Flysystem: пути бекапов (`servers/5/…/files.tar.gz`) кладутся в папку `$root`
 * (`disk:/SystemKeeper` или `app:/` для токена с доступом только к папке приложения).
 */
class YandexDiskAdapter implements FilesystemAdapter
{
    public function __construct(private YandexDiskClient $client, private string $root = 'disk:/SystemKeeper') {}

    public function client(): YandexDiskClient
    {
        return $this->client;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** Полный путь на Диске для пути внутри хранилища. */
    public function fullPath(string $path): string
    {
        return $this->full($path);
    }

    private function full(string $path): string
    {
        $path = trim($path, '/');

        return rtrim($this->root, '/').($path === '' ? '' : '/'.$path);
    }

    /** Путь от корня хранилища из полного пути Диска. */
    private function relative(string $full): string
    {
        $root = rtrim($this->root, '/');

        return ltrim(str_starts_with($full, $root) ? substr($full, strlen($root)) : $full, '/');
    }

    public function fileExists(string $path): bool
    {
        return ($this->client->info($this->full($path), 'type')['type'] ?? null) === 'file';
    }

    public function directoryExists(string $path): bool
    {
        return ($this->client->info($this->full($path), 'type')['type'] ?? null) === 'dir';
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);
        try {
            $this->writeStream($path, $stream, $config);
        } finally {
            is_resource($stream) && fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        try {
            $this->client->upload($this->full($path), $contents);
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function readStream(string $path)
    {
        try {
            return $this->client->download($this->full($path));
        } catch (Throwable $e) {
            throw UnableToReadFile::fromLocation($path, $e->getMessage(), $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->client->delete($this->full($path));
        } catch (Throwable $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function deleteDirectory(string $path): void
    {
        try {
            $this->client->delete($this->full($path));
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->client->mkdir($this->full($path));
        } catch (Throwable $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'На Яндекс Диске видимость не задаётся');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'private');
    }

    private function attributes(string $path): FileAttributes
    {
        $info = $this->client->info($this->full($path));
        if (($info['type'] ?? null) !== 'file') {
            throw UnableToRetrieveMetadata::create($path, 'metadata', 'Файл не найден');
        }

        return new FileAttributes($path, (int) $info['size'], null, isset($info['modified']) ? strtotime($info['modified']) : null, ($info['mime_type'] ?? '') ?: 'application/octet-stream');
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->attributes($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->attributes($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->attributes($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        foreach ($this->client->list($this->full($path)) as $item) {
            $relative = $this->relative($item['path']);
            if ($item['type'] === 'dir') {
                yield new DirectoryAttributes($relative);
                if ($deep) {
                    yield from $this->listContents($relative, true);
                }
            } else {
                yield new FileAttributes($relative, (int) ($item['size'] ?? 0), null, isset($item['modified']) ? strtotime($item['modified']) : null);
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copy($this->full($source), $this->full($destination), move: true);
        } catch (Throwable $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copy($this->full($source), $this->full($destination));
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }
}
