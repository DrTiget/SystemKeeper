<?php

namespace App\Models;

use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'event', 'target_type', 'target_id', 'quiet_hours_start', 'quiet_hours_end', 'throttle_minutes', 'enabled'])]
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    public const EVENTS = [
        'incident.opened', 'incident.resolved', 'backup.failed', 'backup.succeeded',
        'storage_copy.failed', 'ssl.expiring', 'metric.threshold',
    ];

    /** Фильтр по объекту: тип => таблица. Без фильтра правило действует на все объекты. */
    public const TARGETS = [
        'monitor' => 'monitors',
        'backup_job' => 'backup_jobs',
        'server' => 'servers',
        'storage_target' => 'storage_targets',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'throttle_minutes' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(AlertChannel::class);
    }

    /** Попадает ли «сейчас» в тихие часы (в часовом поясе приложения; интервал может переходить через полночь). */
    public function inQuietHours(): bool
    {
        if ($this->quiet_hours_start === null || $this->quiet_hours_end === null || $this->quiet_hours_start === $this->quiet_hours_end) {
            return false;
        }

        $now = now()->format('H:i');

        return $this->quiet_hours_start < $this->quiet_hours_end
            ? $now >= $this->quiet_hours_start && $now < $this->quiet_hours_end
            : $now >= $this->quiet_hours_start || $now < $this->quiet_hours_end;
    }
}
