<?php

namespace App\Services\Backup;

use App\Models\BackupStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Файловая система хранилища бекапов: локальный диск `backups` или бакет S3.
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
