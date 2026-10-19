<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Точка истории ресурсов сервера; время снятия — `created_at`.
 */
#[Fillable(['server_id', 'created_at', 'cpu', 'memory', 'swap', 'load1', 'load5', 'load15', 'rx', 'tx', 'disks'])]
class ServerMetricPoint extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'cpu' => 'float',
            'memory' => 'float',
            'swap' => 'float',
            'load1' => 'float',
            'load5' => 'float',
            'load15' => 'float',
            'rx' => 'integer',
            'tx' => 'integer',
            'disks' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
