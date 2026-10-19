<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Срабатывание правила на объекте: пока условие выполняется — `firing`, потом `resolved`.
 * На пару «правило + объект» одновременно не больше одного активного алерта.
 */
#[Fillable([
    'alert_rule_id', 'severity', 'status', 'object_type', 'object_id', 'object_name', 'project_id', 'message',
    'started_at', 'resolved_at', 'acknowledged_at', 'acknowledged_by', 'last_notified_at',
])]
class Alert extends Model
{
    public const FIRING = 'firing';

    public const RESOLVED = 'resolved';

    /** Решённые алерты старше стольких дней удаляются (`alerts:prune`) */
    public const RETENTION_DAYS = 90;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AlertNotification::class)->orderBy('id');
    }

    public function isFiring(): bool
    {
        return $this->status === self::FIRING;
    }
}
