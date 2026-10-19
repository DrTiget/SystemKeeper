<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * С какого момента условие правила выполняется на объекте — по этому отсчитывается `duration`.
 */
#[Fillable(['alert_rule_id', 'object_type', 'object_id', 'since'])]
class AlertPending extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['since' => 'datetime'];
    }
}
