<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Канал уведомлений об алертах. Настройки (`config`) шифруются целиком: telegram — `bot_token`, `chat_id`;
 * email — `recipients`; webhook — `url`, `secret` (подпись X-Signature). Секреты наружу не отдаются.
 */
#[Fillable(['name', 'type', 'enabled', 'config', 'last_sent_at', 'last_error'])]
#[Hidden(['config'])]
class AlertChannel extends Model
{
    public const TELEGRAM = 'telegram';

    public const EMAIL = 'email';

    public const WEBHOOK = 'webhook';

    public const TYPES = [self::TELEGRAM, self::EMAIL, self::WEBHOOK];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'config' => 'encrypted:array',
            'last_sent_at' => 'datetime',
        ];
    }

    public function rules(): BelongsToMany
    {
        return $this->belongsToMany(AlertRule::class, 'alert_rule_channel');
    }
}
