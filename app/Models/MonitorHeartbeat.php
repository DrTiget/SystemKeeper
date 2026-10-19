<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна проверка монитора. Время проверки — `created_at`.
 */
#[Fillable(['monitor_id', 'status', 'response_ms', 'status_code', 'message', 'created_at'])]
class MonitorHeartbeat extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
