<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сбой: период, когда монитор был недоступен (`ended_at` пуст, пока сбой продолжается).
 */
#[Fillable(['monitor_id', 'started_at', 'ended_at', 'error'])]
class MonitorIncident extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
