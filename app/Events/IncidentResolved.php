<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;

/** Инцидент закрыт: монитор снова отвечает. Для будущих алертов. */
class IncidentResolved
{
    use Dispatchable;

    public function __construct(public readonly Incident $incident) {}
}
