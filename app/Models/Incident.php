<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monitor_id', 'started_at', 'resolved_at', 'reason', 'duration_seconds'])]
class Incident extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    /** Длительность: для закрытого — сохранённая, для открытого — на текущий момент. */
    public function durationSeconds(): int
    {
        return $this->duration_seconds ?? max(0, (int) $this->started_at->diffInSeconds(now(), true));
    }
}
