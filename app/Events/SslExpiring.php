<?php

namespace App\Events;

use App\Models\Monitor;
use Illuminate\Foundation\Events\Dispatchable;

/** SSL-сертификат скоро истекает (осталось меньше порога монитора, но ещё не истёк). */
class SslExpiring
{
    use Dispatchable;

    public function __construct(public readonly Monitor $monitor, public readonly int $daysLeft) {}
}
