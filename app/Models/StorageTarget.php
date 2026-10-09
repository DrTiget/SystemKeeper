<?php

namespace App\Models;

use Database\Factories\StorageTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'server_id', 'token', 'secret', 'base_path', 'config', 'enabled'])]
#[Hidden(['token', 'secret'])]
class StorageTarget extends Model
{
    /** @use HasFactory<StorageTargetFactory> */
    use HasFactory;

    public const TYPE_LOCAL = 'local';

    public const TYPE_YANDEX_DISK = 'yandex_disk';

    public const TYPE_S3 = 's3';

    public const TYPE_SFTP = 'sftp';

    public const TYPES = [self::TYPE_LOCAL, self::TYPE_YANDEX_DISK, self::TYPE_S3, self::TYPE_SFTP];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'secret' => 'encrypted',
            'config' => 'array',
            'enabled' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_total_space' => 'integer',
            'last_free_space' => 'integer',
        ];
    }

    public function copies(): HasMany
    {
        return $this->hasMany(StorageCopy::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Значение несекретной настройки s3 из config. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }

    public function isYandexDisk(): bool
    {
        return $this->type === self::TYPE_YANDEX_DISK;
    }

    /** Полный путь в хранилище для пути относительно базовой папки. */
    public function resolvePath(string $remotePath): string
    {
        return rtrim($this->base_path, '/').'/'.ltrim($remotePath, '/');
    }
}
