<?php

namespace App\Models;

use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Настройки бекапа сервера: какие каталоги, по какому расписанию и что выполнить перед бекапом.
 */
#[Fillable(['enabled', 'paths', 'excludes', 'schedule', 'pre_command', 'pre_command_timeout'])]
class ServerBackupSettings extends Model
{
    protected $table = 'server_backup_settings';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'paths' => 'array',
            'excludes' => 'array',
            'pre_command_timeout' => 'integer',
        ];
    }

    /** Настройки по умолчанию для сервера, у которого их ещё нет (не сохраняются). */
    public static function defaultsFor(Server $server): self
    {
        $settings = new self([
            'enabled' => false,
            'paths' => [],
            'excludes' => [],
            'schedule' => config('backup.default_schedule'),
            'pre_command' => null,
            'pre_command_timeout' => config('backup.pre_command_timeout'),
        ]);
        $settings->server()->associate($server);

        return $settings;
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Ближайший запуск по расписанию (в часовом поясе бекапов); null — если бекап выключен. */
    public function nextRunAt(?DateTimeInterface $from = null): ?Carbon
    {
        if (! $this->enabled) {
            return null;
        }

        $tz = config('backup.timezone');

        return Carbon::instance((new CronExpression($this->schedule))->getNextRunDate(Carbon::instance($from ?? now())->setTimezone($tz), 0, false, $tz));
    }
}
