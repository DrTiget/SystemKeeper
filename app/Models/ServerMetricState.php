<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Состояние порога по одному показателю (диск — по точке монтирования) для антидребезга. */
#[Fillable(['server_id', 'metric', 'target', 'state', 'breach_streak', 'ok_streak', 'value', 'threshold', 'exceeded_since'])]
class ServerMetricState extends Model
{
    public const STATE_OK = 'ok';

    public const STATE_EXCEEDED = 'exceeded';

    protected function casts(): array
    {
        return [
            'breach_streak' => 'integer',
            'ok_streak' => 'integer',
            'value' => 'float',
            'threshold' => 'float',
            'exceeded_since' => 'datetime',
        ];
    }
}
