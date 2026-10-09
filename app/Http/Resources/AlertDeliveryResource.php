<?php

namespace App\Http\Resources;

use App\Models\AlertDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AlertDelivery
 */
class AlertDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alert_rule_id' => $this->alert_rule_id,
            'alert_channel_id' => $this->alert_channel_id,
            'event' => $this->event,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'status' => $this->status,
            'error' => $this->error,
            'attempts' => $this->attempts,
            'sent_at' => $this->sent_at,
            'created_at' => $this->created_at,
        ];
    }
}
