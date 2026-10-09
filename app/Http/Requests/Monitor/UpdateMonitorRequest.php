<?php

namespace App\Http\Requests\Monitor;

use App\Models\Monitor;

class UpdateMonitorRequest extends StoreMonitorRequest
{
    protected function type(): ?string
    {
        $monitor = $this->route('monitor');

        return $monitor instanceof Monitor ? $monitor->type : null;
    }
}
