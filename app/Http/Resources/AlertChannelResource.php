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
    /** Секреты (токен бота) в ответ не попадают, только флаг has_bot_token. */
    public function toArray(Request $request): array
    {
        $settings = match ($this->type) {
            AlertChannel::TYPE_TELEGRAM => ['chat_id' => $this->setting('chat_id'), 'has_bot_token' => $this->setting('bot_token') !== null],
            AlertChannel::TYPE_EMAIL => ['recipients' => $this->setting('recipients', [])],
            default => (object) [],
        };

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'enabled' => $this->enabled,
            'settings' => $settings,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
