<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Audit\AuditSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('audit:prune')]
#[Description('Удалить записи журнала действий старше срока хранения (планировщик, раз в сутки)')]
class PruneAuditLogs extends Command
{
    public function handle(AuditSettings $settings): int
    {
        $deleted = 0;
        $before = now()->subDays($settings->retentionDays());
        // порциями, чтобы не держать блокировку на большой таблице
        do {
            $batch = AuditLog::query()->where('created_at', '<', $before)->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->line("Удалено записей журнала: {$deleted}");

        return self::SUCCESS;
    }
}
