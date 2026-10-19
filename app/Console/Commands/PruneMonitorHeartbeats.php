<?php

namespace App\Console\Commands;

use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitors:prune')]
#[Description('Удалить проверки мониторов старше 30 дней (планировщик, раз в сутки)')]
class PruneMonitorHeartbeats extends Command
{
    public function handle(): int
    {
        $deleted = 0;
        $before = now()->subDays(Monitor::RETENTION_DAYS);
        // порциями, чтобы не держать блокировку на большой таблице
        while (($n = MonitorHeartbeat::query()->where('created_at', '<', $before)->limit(10000)->delete()) > 0) {
            $deleted += $n;
        }

        $this->line("Удалено проверок: {$deleted}");

        return self::SUCCESS;
    }
}
