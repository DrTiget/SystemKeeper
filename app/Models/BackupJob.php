<?php

namespace App\Models;

use Cron\CronExpression;
use Database\Factories\BackupJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable([
    'name', 'server_id', 'type', 'paths', 'excludes',
    'db_mode', 'db_host', 'db_port', 'db_name', 'db_user', 'db_password', 'db_ssl_verify',
    'compression_level', 'schedule', 'enabled', 'last_scheduled_at',
    'retention_count', 'retention_days', 'storage_target_ids',
])]
#[Hidden(['db_password'])]
class BackupJob extends Model
{
    /** @use HasFactory<BackupJobFactory> */
    use HasFactory;

    public const TYPE_FILES = 'files';

    public const TYPE_MYSQL = 'mysql';

    public const TYPE_POSTGRES = 'postgres';

    public const TYPES = [self::TYPE_FILES, self::TYPE_MYSQL, self::TYPE_POSTGRES];

    /** Дамп выполняется на сервере по SSH */
    public const DB_MODE_SSH = 'ssh';

    /** SystemKeeper сам подключается к БД и дампит локально */
    public const DB_MODE_DIRECT = 'direct';

    protected static function booted(): void
    {
        // Удаление через Eloquent, чтобы Backup::deleting убрал файлы с диска
        static::deleting(fn (BackupJob $job) => $job->backups()->get()->each->delete());
    }

    protected function casts(): array
    {
        return [
            'paths' => 'array',
            'excludes' => 'array',
            'storage_target_ids' => 'array',
            'db_port' => 'integer',
            'db_password' => 'encrypted',
            'db_ssl_verify' => 'boolean',
            'compression_level' => 'integer',
            'enabled' => 'boolean',
            'retention_count' => 'integer',
            'retention_days' => 'integer',
            'last_scheduled_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    public function latestBackup(): HasOne
    {
        return $this->hasOne(Backup::class)->latestOfMany();
    }

    public function isDatabase(): bool
    {
        return $this->type !== self::TYPE_FILES;
    }

    public function hasActiveBackup(): bool
    {
        return $this->backups()->whereIn('status', Backup::ACTIVE_STATUSES)->exists();
    }

    public function nextRunAt(): ?Carbon
    {
        if (! $this->enabled || ! $this->schedule || ! CronExpression::isValidExpression($this->schedule)) {
            return null;
        }

        return Carbon::instance((new CronExpression($this->schedule))->getNextRunDate(now()));
    }

    /**
     * Просрочено ли задание: с момента последней постановки в очередь (или создания) наступило время по cron.
     */
    public function isDue(?Carbon $now = null): bool
    {
        if (! $this->enabled || ! $this->schedule || ! CronExpression::isValidExpression($this->schedule)) {
            return false;
        }

        $now ??= now();
        $previous = Carbon::instance((new CronExpression($this->schedule))->getPreviousRunDate($now, 0, true));

        return $previous->gt($this->last_scheduled_at ?? $this->created_at);
    }
}
