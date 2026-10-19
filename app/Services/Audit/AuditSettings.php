<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Setting;

/**
 * Настройки журнала действий в таблице `settings`.
 */
class AuditSettings
{
    public const KEY = 'audit';

    public function retentionDays(): int
    {
        return (int) (Setting::query()->where('key', self::KEY)->first()?->value['retention_days'] ?? AuditLog::RETENTION_DAYS);
    }

    public function setRetentionDays(int $days): void
    {
        Setting::updateOrCreate(['key' => self::KEY], ['value' => ['retention_days' => $days]]);
    }
}
