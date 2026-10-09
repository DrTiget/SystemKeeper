<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'server_id', 'collected_at', 'load1', 'load5', 'load15', 'cpu_count', 'mem_total', 'mem_used', 'mem_available',
    'swap_total', 'swap_used', 'uptime_seconds', 'disks',
])]
class ServerMetric extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'load1' => 'float',
            'load5' => 'float',
            'load15' => 'float',
            'cpu_count' => 'integer',
            'mem_total' => 'integer',
            'mem_used' => 'integer',
            'mem_available' => 'integer',
            'swap_total' => 'integer',
            'swap_used' => 'integer',
            'uptime_seconds' => 'integer',
            'disks' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Занятая память, % (без кэша: total − available). */
    public function memUsedPercent(): float
    {
        return $this->mem_total > 0 ? round($this->mem_used / $this->mem_total * 100, 1) : 0.0;
    }

    public function swapUsedPercent(): ?float
    {
        return $this->swap_total > 0 ? round($this->swap_used / $this->swap_total * 100, 1) : null;
    }

    /** Нагрузка (load average за 5 минут) на одно ядро. */
    public function loadPerCore(): float
    {
        return round($this->load5 / max($this->cpu_count ?: 1, 1), 2);
    }

    /** Занятость диска, % — как «Use%» в df: used / (used + avail). */
    public static function diskUsedPercent(array $disk): float
    {
        $base = ($disk['used'] ?? 0) + ($disk['avail'] ?? 0);

        return $base > 0 ? round($disk['used'] / $base * 100, 1) : 0.0;
    }
}
