<?php

namespace App\Models;

use App\Services\Backup\Retention;
use Cron\CronExpression;
use Database\Factories\DatabaseConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Подключение к СУБД (MySQL/MariaDB или PostgreSQL) и настройки её бекапа:
 * какие базы и таблицы дампить, как часто, с какой блокировкой.
 */
#[Fillable([
    'project_id', 'name', 'description', 'engine', 'via', 'server_id', 'docker_container', 'host', 'port', 'username', 'password',
    'maintenance_database', 'backup_enabled', 'schedule', 'databases', 'exclude_tables', 'schema_only_tables', 'options',
    'last_checked_at', 'last_check_status', 'last_check_error', 'server_version', 'storage_ids', 'keep_count', 'max_size',
])]
#[Hidden(['password'])]
class DatabaseConnection extends Model
{
    /** @use HasFactory<DatabaseConnectionFactory> */
    use HasFactory;

    public const MYSQL = 'mysql';

    public const POSTGRES = 'postgres';

    public const CLICKHOUSE = 'clickhouse';

    public const ENGINES = [self::MYSQL, self::POSTGRES, self::CLICKHOUSE];

    public const VIA_SSH = 'ssh';

    public const VIA_DIRECT = 'direct';

    /** ClickHouse — нативный протокол clickhouse-client */
    public const DEFAULT_PORTS = [self::MYSQL => 3306, self::POSTGRES => 5432, self::CLICKHOUSE => 9000];

    /** Служебные базы — не предлагаются к бекапу по умолчанию. */
    public const SYSTEM_DATABASES = [
        self::MYSQL => ['information_schema', 'performance_schema', 'mysql', 'sys'],
        self::POSTGRES => ['postgres'],
        self::CLICKHOUSE => ['system', 'INFORMATION_SCHEMA', 'information_schema'],
    ];

    /** Параметры дампа по умолчанию; в БД — только известные ключи. */
    public const DEFAULT_OPTIONS = [
        self::MYSQL => ['lock_mode' => 'single_transaction', 'routines' => true, 'events' => true, 'triggers' => true, 'compress' => true],
        self::POSTGRES => ['format' => 'custom', 'no_owner' => false, 'compress' => true],
        self::CLICKHOUSE => ['compress' => true],
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'backup_enabled' => false,
        'databases' => '[]',
        'exclude_tables' => '[]',
        'schema_only_tables' => '[]',
        'options' => '{}',
        'keep_count' => 7,
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function backups(): MorphMany
    {
        return $this->morphMany(Backup::class, 'source');
    }

    protected static function booted(): void
    {
        // по одному, чтобы удалились и файлы бекапов
        static::deleting(fn (self $model) => $model->backups()->each(fn (Backup $b) => $b->delete()));
    }

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'backup_enabled' => 'boolean',
            'databases' => 'array',
            'exclude_tables' => 'array',
            'schema_only_tables' => 'array',
            'options' => 'array',
            'last_checked_at' => 'datetime',
            'storage_ids' => 'array',
            'keep_count' => 'integer',
            'max_size' => 'integer',
        ];
    }

    /** Настройки хранения бекапов: хранилище, сколько копий держать и предельный объём. */
    public function retention(): Retention
    {
        return new Retention($this->storage_ids ?? [], $this->keep_count, $this->max_size);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Параметры дампа с подставленными значениями по умолчанию.
     *
     * @return array<string, mixed>
     */
    public function resolvedOptions(): array
    {
        $defaults = self::DEFAULT_OPTIONS[$this->engine] ?? [];

        return array_merge($defaults, array_intersect_key($this->options ?? [], $defaults));
    }

    /** Ближайший запуск по расписанию; null — если бекап выключен. */
    public function nextRunAt(): ?Carbon
    {
        if (! $this->backup_enabled) {
            return null;
        }
        $tz = config('backup.timezone');

        return Carbon::instance((new CronExpression($this->schedule))->getNextRunDate(now()->setTimezone($tz), 0, false, $tz));
    }
}
