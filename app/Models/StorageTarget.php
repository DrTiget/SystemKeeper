<?php

namespace App\Models;

use Database\Factories\StorageTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'token', 'base_path', 'enabled'])]
#[Hidden(['token'])]
class StorageTarget extends Model
{
    /** @use HasFactory<StorageTargetFactory> */
    use HasFactory;

    public const TYPE_LOCAL = 'local';

    public const TYPE_YANDEX_DISK = 'yandex_disk';

    public const TYPES = [self::TYPE_LOCAL, self::TYPE_YANDEX_DISK];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'enabled' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function copies(): HasMany
    {
        return $this->hasMany(StorageCopy::class);
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
