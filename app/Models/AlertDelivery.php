<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['alert_rule_id', 'alert_channel_id', 'event', 'object_key', 'title', 'body', 'url', 'status', 'error', 'attempts', 'sent_at'])]
class AlertDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Не отправлено намеренно: тихие часы, антиспам, отключённый драйвер. */
    public const STATUS_SKIPPED = 'skipped';

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'sent_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AlertChannel::class, 'alert_channel_id');
    }
}
