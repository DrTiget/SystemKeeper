<?php

namespace App\Http\Resources;

use App\Models\ServerMetric;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServerMetric
 */
class ServerMetricResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'collected_at' => $this->collected_at,
            'load' => ['load1' => $this->load1, 'load5' => $this->load5, 'load15' => $this->load15, 'per_core' => $this->loadPerCore()],
            'cpu_count' => $this->cpu_count,
            'memory' => [
                'total_bytes' => $this->mem_total,
                'used_bytes' => $this->mem_used,
                'available_bytes' => $this->mem_available,
                'used_percent' => $this->memUsedPercent(),
            ],
            'swap' => [
                'total_bytes' => $this->swap_total,
                'used_bytes' => $this->swap_used,
                'used_percent' => $this->swapUsedPercent(),
            ],
            'uptime_seconds' => $this->uptime_seconds,
            'disks' => array_map(fn (array $d) => [
                'mount' => $d['mount'],
                'device' => $d['device'],
                'fstype' => $d['fstype'],
                'total_bytes' => $d['total'],
                'used_bytes' => $d['used'],
                'avail_bytes' => $d['avail'],
                'used_percent' => ServerMetric::diskUsedPercent($d),
                'inodes_total' => $d['inodes_total'],
                'inodes_used' => $d['inodes_used'],
                'inodes_free' => $d['inodes_free'],
            ], $this->disks),
        ];
    }
}
