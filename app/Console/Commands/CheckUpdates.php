<?php

namespace App\Console\Commands;

use App\Services\System\UpdateChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('system:check-updates')]
#[Description('Проверить, вышла ли новая версия SystemKeeper (релизы GitHub)')]
class CheckUpdates extends Command
{
    public function handle(UpdateChecker $checker): int
    {
        if (! config('systemkeeper.update_check')) {
            return self::SUCCESS;
        }

        $state = $checker->check();

        if ($state['error']) {
            $this->warn($state['error']);
        } elseif ($checker->updateAvailable()) {
            $this->info("Доступна версия {$state['release']['version']} (установлена {$checker->current()})");
        } else {
            $this->line('Установлена последняя версия');
        }

        return self::SUCCESS;
    }
}
