<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monitor_id', 'status', 'response_time_ms', 'status_code', 'error', 'checked_at'])]
class MonitorCheck extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'response_time_ms' => 'integer',
            'status_code' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
