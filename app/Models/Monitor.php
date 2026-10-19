<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Монитор доступности: HTTP(S), TCP-порт, ping или DNS-запись. Состояние (статус, счётчик неудач,
 * время следующей проверки) хранится здесь, каждая проверка — в `monitor_heartbeats`, сбои — в `monitor_incidents`.
 */
#[Fillable([
    'project_id', 'name', 'type', 'enabled', 'url', 'hostname', 'port', 'method', 'accepted_statuses', 'keyword',
    'keyword_invert', 'headers', 'body', 'follow_redirects', 'ignore_tls', 'ssl_expiry_days', 'dns_record_type',
    'dns_resolver', 'dns_expected', 'interval', 'timeout', 'retries', 'retry_interval',
])]
class Monitor extends Model
{
    use Auditable;

    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    public const HTTP = 'http';

    public const TCP = 'tcp';

    public const PING = 'ping';

    public const DNS = 'dns';

    public const TYPES = [self::HTTP, self::TCP, self::PING, self::DNS];

    public const UP = 'up';

    public const DOWN = 'down';

    public const PENDING = 'pending';

    public const PAUSED = 'paused';

    public const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public const DNS_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'];

    /** Проверки старше стольких дней удаляются (`monitors:prune`) */
    public const RETENTION_DAYS = 30;

    /** Статистика для ответа API (uptime, среднее время, последние проверки) — см. MonitorStats */
    public ?array $stats = null;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'accepted_statuses' => 'array',
            'keyword_invert' => 'boolean',
            'headers' => 'encrypted:array',
            'follow_redirects' => 'boolean',
            'ignore_tls' => 'boolean',
            'status_since' => 'datetime',
            'last_check_at' => 'datetime',
            'next_check_at' => 'datetime',
            'ssl_valid_to' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(MonitorHeartbeat::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(MonitorIncident::class);
    }

    /** Статус для клиента: на паузе, если монитор выключен. */
    public function effectiveStatus(): string
    {
        return $this->enabled ? $this->status : self::PAUSED;
    }
}
