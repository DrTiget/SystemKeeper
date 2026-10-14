<?php

namespace App\Console\Commands;

use App\Models\Backup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('system:idle')]
#[Description('Код 0, если сейчас не идёт ни один бекап (updater ждёт этого перед обновлением)')]
class SystemIdle extends Command
{
    public function handle(): int
    {
        // Бекап длится не дольше 4 часов; «running» старше — остаток упавшего воркера, он не мешает
        $running = Backup::query()
            ->where('status', 'running')
            ->where('updated_at', '>', now()->subHours(4)->subMinutes(15))
            ->count();

        if ($running > 0) {
            $this->line("Идёт бекапов: {$running}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
