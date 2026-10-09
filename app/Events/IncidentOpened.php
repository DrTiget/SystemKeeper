<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;

/** Инцидент открыт: монитор упал N проверок подряд. Для будущих алертов. */
class IncidentOpened
{
    use Dispatchable;

    public function __construct(public readonly Incident $incident) {}
}
