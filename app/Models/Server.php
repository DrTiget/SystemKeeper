<?php

namespace App\Models;

use App\Services\Backup\Retention;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Сервер с SSH-подключением: по ключу (private_key + необязательная passphrase) или по паролю.
 */
#[Fillable([
    'project_id', 'name', 'description', 'host', 'port', 'username', 'auth_type', 'private_key', 'passphrase', 'public_key', 'password',
    'host_fingerprint', 'last_checked_at', 'last_check_status', 'last_check_error',
])]
#[Hidden(['private_key', 'passphrase', 'password'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    public const AUTH_KEY = 'key';

    public const AUTH_PASSWORD = 'password';

    public const AUTH_TYPES = [self::AUTH_KEY, self::AUTH_PASSWORD];

    /** @var array<string, mixed> */
    protected $attributes = ['port' => 22];

    public function backupSettings(): HasOne
    {
        return $this->hasOne(ServerBackupSettings::class);
    }

    /** Сохранённые настройки бекапа или значения по умолчанию. */
    public function backupSettingsOrDefault(): ServerBackupSettings
    {
        return $this->backupSettings ?? ServerBackupSettings::defaultsFor($this);
    }

    /** Настройки хранения бекапов: хранилище, сколько копий держать и предельный объём. */
    public function retention(): Retention
    {
        $s = $this->backupSettingsOrDefault();

        return new Retention($s->storage_ids ?? [], $s->keep_count, $s->max_size);
    }

    public function metrics(): HasOne
    {
        return $this->hasOne(ServerMetrics::class);
    }

    /** Настройки сбора ресурсов и последний снимок или значения по умолчанию (не сохраняются). */
    public function metricsOrDefault(): ServerMetrics
    {
        return $this->metrics ?? (new ServerMetrics)->setRelation('server', $this)->forceFill(['server_id' => $this->id]);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function backups(): MorphMany
    {
        return $this->morphMany(Backup::class, 'source');
    }

    public function backupHook(): MorphOne
    {
        return $this->morphOne(BackupHook::class, 'source');
    }

    protected static function booted(): void
    {
        // по одному, чтобы удалились и файлы бекапов
        static::deleting(function (self $model) {
            $model->backups()->each(fn (Backup $b) => $b->delete());
            $model->backupHook()->delete();
        });
    }

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
            'password' => 'encrypted',
            'last_checked_at' => 'datetime',
        ];
    }
}
