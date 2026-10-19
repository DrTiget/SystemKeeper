<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** user, application или system */
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            /** Например `server.updated`, `auth.login`, `backup.failed`, `alert.fired`, `request` */
            'event' => $this->event,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'subject_label' => $this->subject_label,
            /** Изменения или подробности; секреты заменены на `***` */
            'changes' => $this->changes,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at,
        ];
    }
}
