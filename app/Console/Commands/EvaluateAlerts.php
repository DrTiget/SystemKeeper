<?php

namespace App\Console\Commands;

use App\Services\Alerts\AlertEngine;
use App\Services\System\Updater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:evaluate')]
#[Description('Оценить правила алертов: создать, повторить и закрыть алерты, поставить уведомления в очередь (планировщик, каждую минуту)')]
class EvaluateAlerts extends Command
{
    public function handle(AlertEngine $engine, Updater $updater): int
    {
        // во время обновления свежих данных нет — по старым не судим
        if ($updater->locked()) {
            $this->line('Идёт обновление SystemKeeper — оценка алертов пропущена');

            return self::SUCCESS;
        }

        $stats = $engine->run();
        $this->line("Сработало: {$stats['fired']}, напоминаний: {$stats['repeated']}, решено: {$stats['resolved']}");

        return self::SUCCESS;
    }
}
