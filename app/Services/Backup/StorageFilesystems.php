<?php

namespace App\Services\Backup;

use App\Models\BackupStorage;
use App\Services\Storage\YandexDiskAdapter;
use App\Services\Storage\YandexDiskClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;

/**
 * Файловая система хранилища бекапов: локальный диск `backups`, бакет S3 или папка на Яндекс Диске.
 * Пути внутри — одинаковые (`servers/5/2026-10-14_120000-17/files.tar.gz`).
 */
class StorageFilesystems
{
    public function for(BackupStorage $storage): Filesystem
    {
        if ($storage->type === BackupStorage::LOCAL) {
            return Storage::disk('backups');
        }

        $s = $storage->settings ?? [];

        if ($storage->type === BackupStorage::YANDEX_DISK) {
            $adapter = new YandexDiskAdapter(new YandexDiskClient((string) ($s['token'] ?? '')), ($s['folder'] ?? '') ?: 'disk:/SystemKeeper');
            $config = ['throw' => true, 'report' => false];

            return new FilesystemAdapter(new Flysystem($adapter, $config), $adapter, $config);
        }

        return Storage::build([
            'driver' => 's3',
            'key' => $s['key'] ?? '',
            'secret' => $s['secret'] ?? '',
            'region' => ($s['region'] ?? '') ?: 'us-east-1',
            'bucket' => $s['bucket'] ?? '',
            'endpoint' => ($s['endpoint'] ?? '') ?: null,
            'use_path_style_endpoint' => (bool) ($s['path_style'] ?? false),
            'root' => trim((string) ($s['prefix'] ?? ''), '/'),
            'throw' => true,
            'report' => false,
        ]);
    }
}
