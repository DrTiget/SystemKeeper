<?php

namespace App\Models;

use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name', 'type', 'target', 'interval_seconds', 'timeout_seconds', 'expected_status', 'keyword',
    'ssl_expiry_days', 'heartbeat_grace_seconds', 'failure_threshold', 'enabled',
])]
class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    public const TYPE_HTTP = 'http';

    public const TYPE_KEYWORD = 'keyword';

    public const TYPE_TCP = 'tcp';

    public const TYPE_PING = 'ping';

    public const TYPE_SSL = 'ssl';

    public const TYPE_HEARTBEAT = 'heartbeat';

    public const TYPES = [
        self::TYPE_HTTP, self::TYPE_KEYWORD, self::TYPE_TCP, self::TYPE_PING, self::TYPE_SSL, self::TYPE_HEARTBEAT,
    ];

    public const STATUS_UP = 'up';

    public const STATUS_DOWN = 'down';

    protected function casts(): array
    {
        return [
            'interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
            'expected_status' => 'integer',
            'ssl_expiry_days' => 'integer',
            'heartbeat_grace_seconds' => 'integer',
            'failure_threshold' => 'integer',
            'consecutive_failures' => 'integer',
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function openIncident(): HasOne
    {
        return $this->hasOne(Incident::class)->whereNull('resolved_at');
    }

    /** Включённые мониторы, которым пора на проверку. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('enabled', true)->where(function (Builder $q) {
            $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
        });
    }

    public function isHeartbeat(): bool
    {
        return $this->type === self::TYPE_HEARTBEAT;
    }

    /**
     * Хост и порт цели (для tcp «host:port», для ssl «host», «host:port» или URL).
     *
     * @return array{0: string, 1: int}
     */
    public function hostPort(int $defaultPort): array
    {
        $target = (string) $this->target;
        if (str_contains($target, '://')) {
            $parts = parse_url($target) ?: [];

            return [(string) ($parts['host'] ?? ''), (int) ($parts['port'] ?? $defaultPort)];
        }
        if (($pos = strrpos($target, ':')) !== false && ! str_ends_with($target, ']')) {
            return [substr($target, 0, $pos), (int) substr($target, $pos + 1)];
        }

        return [$target, $defaultPort];
    }
}
