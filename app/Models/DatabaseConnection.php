<?php

namespace App\Models;

use Cron\CronExpression;
use Database\Factories\DatabaseConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Подключение к СУБД (MySQL/MariaDB или PostgreSQL) и настройки её бекапа:
 * какие базы и таблицы дампить, как часто, с какой блокировкой.
 */
#[Fillable([
    'name', 'description', 'engine', 'via', 'server_id', 'docker_container', 'host', 'port', 'username', 'password',
    'maintenance_database', 'backup_enabled', 'schedule', 'databases', 'exclude_tables', 'schema_only_tables', 'options',
    'last_checked_at', 'last_check_status', 'last_check_error', 'server_version',
])]
#[Hidden(['password'])]
class DatabaseConnection extends Model
{
    /** @use HasFactory<DatabaseConnectionFactory> */
    use HasFactory;

    public const MYSQL = 'mysql';

    public const POSTGRES = 'postgres';

    public const ENGINES = [self::MYSQL, self::POSTGRES];

    public const VIA_SSH = 'ssh';

    public const VIA_DIRECT = 'direct';

    public const DEFAULT_PORTS = [self::MYSQL => 3306, self::POSTGRES => 5432];

    /** Служебные базы — не предлагаются к бекапу по умолчанию. */
    public const SYSTEM_DATABASES = [
        self::MYSQL => ['information_schema', 'performance_schema', 'mysql', 'sys'],
        self::POSTGRES => ['postgres'],
    ];

    /** Параметры дампа по умолчанию; в БД — только известные ключи. */
    public const DEFAULT_OPTIONS = [
        self::MYSQL => ['lock_mode' => 'single_transaction', 'routines' => true, 'events' => true, 'triggers' => true, 'compress' => true],
        self::POSTGRES => ['format' => 'custom', 'no_owner' => false, 'compress' => true],
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'backup_enabled' => false,
        'databases' => '[]',
        'exclude_tables' => '[]',
        'schema_only_tables' => '[]',
        'options' => '{}',
    ];

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
        ];
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
