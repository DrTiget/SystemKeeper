<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Запись журнала действий. Пишется через App\Services\Audit\Audit.
 */
#[Fillable(['actor_type', 'actor_id', 'actor_name', 'event', 'subject_type', 'subject_id', 'subject_label', 'changes', 'ip', 'user_agent', 'created_at'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTOR_USER = 'user';

    public const ACTOR_APPLICATION = 'application';

    public const ACTOR_SYSTEM = 'system';

    /** Срок хранения по умолчанию, дней (меняется в настройках журнала). */
    public const RETENTION_DAYS = 90;

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }
}
