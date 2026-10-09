<?php

namespace App\Models;

use Database\Factories\AlertChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'type', 'settings', 'enabled'])]
#[Hidden(['settings'])]
class AlertChannel extends Model
{
    /** @use HasFactory<AlertChannelFactory> */
    use HasFactory;

    public const TYPE_TELEGRAM = 'telegram';

    public const TYPE_EMAIL = 'email';

    public const TYPE_WEBPUSH = 'webpush';

    public const TYPE_MOBILE = 'mobile';

    public const TYPES = [self::TYPE_TELEGRAM, self::TYPE_EMAIL, self::TYPE_WEBPUSH, self::TYPE_MOBILE];

    protected function casts(): array
    {
        return [
            // Настройки целиком (токен бота и т.п.) хранятся зашифрованными
            'settings' => 'encrypted:array',
            'enabled' => 'boolean',
        ];
    }

    public function rules(): BelongsToMany
    {
        return $this->belongsToMany(AlertRule::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }
}
