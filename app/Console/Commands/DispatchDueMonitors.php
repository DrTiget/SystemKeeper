<?php

namespace App\Console\Commands;

use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Services\System\Updater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitors:dispatch-due')]
#[Description('Поставить в очередь проверки мониторов, которым подошло время (планировщик, каждую минуту)')]
class DispatchDueMonitors extends Command
{
    public function handle(Updater $updater): int
    {
        if ($updater->locked()) {
            $this->line('Идёт обновление SystemKeeper — проверки мониторов пропущены');

            return self::SUCCESS;
        }

        $count = 0;
        Monitor::query()->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
            ->each(function (Monitor $monitor) use (&$count) {
                // занимаем монитор на время проверки: следующий запуск планировщика не поставит его второй раз
                $claimed = Monitor::query()->whereKey($monitor->id)
                    ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now()))
                    ->update(['next_check_at' => now()->addSeconds($monitor->timeout + 60)]);
                if ($claimed) {
                    CheckMonitor::dispatch($monitor);
                    $count++;
                }
            });

        $this->line("Поставлено проверок: {$count}");

        return self::SUCCESS;
    }
}
