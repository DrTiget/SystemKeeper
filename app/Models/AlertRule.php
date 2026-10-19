<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Правило алерта: что отслеживать (`kind`), порог, сколько минут условие должно держаться, область (проект и объекты),
 * куда слать и как напоминать. См. App\Services\Alerts\AlertKinds и AlertEngine.
 */
#[Fillable(['name', 'enabled', 'kind', 'severity', 'threshold', 'duration', 'project_id', 'targets', 'repeat_every', 'notify_resolved', 'last_fired_at'])]
class AlertRule extends Model
{
    use Auditable;

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const SEVERITIES = [self::WARNING, self::CRITICAL];

    /** @var array<string, mixed> */
    protected $attributes = ['targets' => '[]', 'duration' => 0, 'enabled' => true, 'notify_resolved' => true];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'threshold' => 'float',
            'duration' => 'integer',
            'targets' => 'array',
            'repeat_every' => 'integer',
            'notify_resolved' => 'boolean',
            'last_fired_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(AlertChannel::class, 'alert_rule_channel');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function pendings(): HasMany
    {
        return $this->hasMany(AlertPending::class);
    }
}
