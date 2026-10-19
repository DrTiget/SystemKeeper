<?php

namespace App\Console\Commands;

use App\Jobs\CollectServerMetrics;
use App\Models\Server;
use App\Models\ServerMetrics;
use App\Services\System\Updater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('metrics:dispatch-due')]
#[Description('Поставить в очередь сбор ресурсов серверов, которым подошло время (планировщик, каждую минуту)')]
class DispatchDueServerMetrics extends Command
{
    public function handle(Updater $updater): int
    {
        if ($updater->locked()) {
            $this->line('Идёт обновление SystemKeeper — сбор ресурсов серверов пропущен');

            return self::SUCCESS;
        }

        // у новых серверов сбор включён по умолчанию — заводим им настройки
        Server::query()->whereDoesntHave('metrics')->each(fn (Server $s) => ServerMetrics::query()->firstOrCreate(['server_id' => $s->id]));

        $due = fn ($q) => $q->whereNull('next_collect_at')->orWhere('next_collect_at', '<=', now());
        $count = 0;
        ServerMetrics::query()->where('enabled', true)->where($due)->each(function (ServerMetrics $metrics) use ($due, &$count) {
            // занимаем сервер на время сбора: следующий запуск планировщика не поставит его второй раз
            $claimed = ServerMetrics::query()->whereKey($metrics->id)->where('enabled', true)->where($due)
                ->update(['next_collect_at' => now()->addSeconds(90)]);
            if ($claimed) {
                CollectServerMetrics::dispatch($metrics->server_id);
                $count++;
            }
        });

        $this->line("Поставлено сборов: {$count}");

        return self::SUCCESS;
    }
}
