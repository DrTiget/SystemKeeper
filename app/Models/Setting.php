<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Системная настройка: ключ → массив значений. Значение шифруется целиком (внутри бывают пароли SMTP и прокси).
 */
#[Fillable(['key', 'value'])]
#[Hidden(['value'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'encrypted:array'];
    }
}
