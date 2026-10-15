<?php

namespace App\Console\Commands;

use App\Services\System\Updater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('system:idle')]
#[Description('Код 0, если сейчас не идёт ни один бекап и ни одна синхронизация (updater ждёт этого перед обновлением)')]
class SystemIdle extends Command
{
    public function handle(): int
    {
        $busy = Updater::busy();

        if ($busy['backups'] + $busy['syncs'] > 0) {
            $this->line("Идёт бекапов: {$busy['backups']}, синхронизаций: {$busy['syncs']}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
