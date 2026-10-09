<?php

namespace App\Console\Commands;

use App\Models\MonitorCheck;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitors:prune {--days= : Хранить результаты за N дней (по умолчанию monitoring.check_retention_days)}')]
#[Description('Удаляет старые результаты проверок мониторов')]
class PruneMonitorChecks extends Command
{
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('monitoring.check_retention_days'));
        $deleted = MonitorCheck::query()->where('checked_at', '<', now()->subDays($days))->delete();

        $this->info("Удалено результатов проверок: {$deleted}");

        return self::SUCCESS;
    }
}
