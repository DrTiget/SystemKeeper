<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'host', 'port', 'username', 'auth_type', 'ssh_key_id', 'password', 'description',
    'last_checked_at', 'last_check_status', 'last_check_error', 'host_fingerprint',
    'metrics_enabled', 'metrics_interval_minutes', 'threshold_disk_percent', 'threshold_load_per_core',
    'threshold_ram_percent', 'threshold_confirmations', 'metrics_next_at', 'metrics_collected_at', 'metrics_last_error',
])]
#[Hidden(['password'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    public const AUTH_KEY = 'key';

    public const AUTH_PASSWORD = 'password';

    protected static function booted(): void
    {
        // Задания бекапов удаляются через Eloquent, чтобы вместе с ними удалились файлы бекапов
        static::deleting(fn (Server $server) => $server->backupJobs()->get()->each->delete());
    }

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'last_checked_at' => 'datetime',
            'metrics_enabled' => 'boolean',
            'metrics_interval_minutes' => 'integer',
            'threshold_disk_percent' => 'integer',
            'threshold_load_per_core' => 'float',
            'threshold_ram_percent' => 'integer',
            'threshold_confirmations' => 'integer',
            'metrics_next_at' => 'datetime',
            'metrics_collected_at' => 'datetime',
        ];
    }

    public function sshKey(): BelongsTo
    {
        return $this->belongsTo(SshKey::class);
    }

    public function storageTargets(): HasMany
    {
        return $this->hasMany(StorageTarget::class);
    }

    public function backupJobs(): HasMany
    {
        return $this->hasMany(BackupJob::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    public function metricStates(): HasMany
    {
        return $this->hasMany(ServerMetricState::class);
    }

    /** Серверы, у которых подошёл срок сбора метрик. */
    public function scopeMetricsDue(Builder $query): Builder
    {
        return $query->where('metrics_enabled', true)
            ->where(fn (Builder $q) => $q->whereNull('metrics_next_at')->orWhere('metrics_next_at', '<=', now()));
    }
}
