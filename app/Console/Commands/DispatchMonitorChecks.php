<?php

namespace App\Console\Commands;

use App\Jobs\RunMonitorCheck;
use App\Models\Monitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitors:dispatch')]
#[Description('Ставит в очередь проверки мониторов, срок которых подошёл')]
class DispatchMonitorChecks extends Command
{
    public function handle(): int
    {
        $count = 0;

        Monitor::query()->due()->orderBy('next_check_at')->limit((int) config('monitoring.dispatch_limit'))->get()
            ->each(function (Monitor $monitor) use (&$count) {
                // Сдвигаем срок заранее, чтобы следующий запуск не поставил проверку повторно
                $monitor->forceFill(['next_check_at' => now()->addSeconds($monitor->interval_seconds)])->save();
                RunMonitorCheck::dispatch($monitor->id);
                $count++;
            });

        $this->info("В очередь поставлено проверок: {$count}");

        return self::SUCCESS;
    }
}
