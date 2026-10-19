<?php

namespace App\Console\Commands;

use App\Models\Alert;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:prune')]
#[Description('Удалить решённые алерты старше 90 дней (планировщик, раз в сутки)')]
class PruneAlerts extends Command
{
    public function handle(): int
    {
        $deleted = Alert::query()->where('status', Alert::RESOLVED)->where('resolved_at', '<', now()->subDays(Alert::RETENTION_DAYS))->delete();
        $this->line("Удалено алертов: {$deleted}");

        return self::SUCCESS;
    }
}
