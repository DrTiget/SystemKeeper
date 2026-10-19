<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Настройки сбора ресурсов сервера и последний снимок: что и как часто снимать, сколько хранить историю.
 * Строка создаётся при первом сборе или при сохранении настроек; до этого действуют значения по умолчанию.
 *
 * @property array{cpu: float|null, cpu_cores: int|null, load: array<int, float>|null, memory: array{used: int, total: int}|null, swap: array{used: int, total: int}|null, disks: list<array{mount: string, device: string|null, used: int, total: int}>, network: array{rx: int, tx: int}|null, uptime: int|null, os: string|null}|null $snapshot
 */
#[Fillable(['server_id', 'enabled', 'interval', 'retention_days', 'disks_exclude', 'next_collect_at', 'last_attempt_at', 'collected_at', 'snapshot', 'error'])]
class ServerMetrics extends Model
{
    public const OK = 'ok';

    public const STALE = 'stale';

    public const UNREACHABLE = 'unreachable';

    public const DISABLED = 'disabled';

    public const DEFAULT_INTERVAL = 60;

    public const DEFAULT_RETENTION_DAYS = 30;

    /** Данные устаревают, если не обновлялись дольше стольких интервалов сбора */
    public const STALE_AFTER_INTERVALS = 3;

    /** @var array<string, mixed> */
    protected $attributes = ['enabled' => true, 'interval' => self::DEFAULT_INTERVAL, 'retention_days' => self::DEFAULT_RETENTION_DAYS, 'disks_exclude' => '[]'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'interval' => 'integer',
            'retention_days' => 'integer',
            'disks_exclude' => 'array',
            'next_collect_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'collected_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** ok / stale / unreachable / disabled */
    public function status(): string
    {
        if (! $this->enabled) {
            return self::DISABLED;
        }
        if ($this->error !== null) {
            return self::UNREACHABLE;
        }
        if (! $this->collected_at || $this->collected_at->lt(now()->subSeconds($this->interval * self::STALE_AFTER_INTERVALS))) {
            return self::STALE;
        }

        return self::OK;
    }
}
