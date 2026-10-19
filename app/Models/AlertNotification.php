<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отправленное (или неудавшееся) уведомление об алерте в канал: `event` — firing, repeat или resolved.
 */
#[Fillable(['alert_id', 'alert_channel_id', 'event', 'ok', 'error', 'created_at'])]
class AlertNotification extends Model
{
    public const UPDATED_AT = null;

    public const FIRING = 'firing';

    public const REPEAT = 'repeat';

    public const RESOLVED = 'resolved';

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'created_at' => 'datetime'];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AlertChannel::class, 'alert_channel_id');
    }
}
