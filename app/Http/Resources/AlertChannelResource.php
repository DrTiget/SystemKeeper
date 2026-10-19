<?php

namespace App\Http\Resources;

use App\Models\AlertChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AlertChannel
 */
class AlertChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $config = $this->config ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            /** telegram, email или webhook */
            'type' => $this->type,
            'enabled' => $this->enabled,
            /** Настройки без секретов: токен бота и секрет вебхука не возвращаются, только `has_token` и `has_secret` */
            'config' => match ($this->type) {
                AlertChannel::TELEGRAM => ['chat_id' => $config['chat_id'] ?? null, 'has_token' => filled($config['bot_token'] ?? null)],
                AlertChannel::EMAIL => ['recipients' => $config['recipients'] ?? []],
                default => ['url' => $config['url'] ?? null, 'has_secret' => filled($config['secret'] ?? null)],
            },
            /** Сколько правил шлют в этот канал */
            'rules_count' => $this->rules_count ?? $this->rules()->count(),
            'last_sent_at' => $this->last_sent_at,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
        ];
    }
}
