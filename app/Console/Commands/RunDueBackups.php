<?php

namespace App\Console\Commands;

use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backups:run-due')]
#[Description('Ставит в очередь бекапы, у которых наступило время по cron, и закрывает зависшие')]
class RunDueBackups extends Command
{
    public function handle(): int
    {
        $this->failStale();

        $queued = 0;
        BackupJob::query()
            ->where('enabled', true)
            ->whereNotNull('schedule')
            ->each(function (BackupJob $job) use (&$queued) {
                if (! $job->isDue()) {
                    return;
                }

                // Пропущенные запуски не копятся: помечаем «обработано», даже если предыдущий бекап ещё идёт
                $job->forceFill(['last_scheduled_at' => now()])->save();

                if ($job->hasActiveBackup()) {
                    $this->warn("Задание #{$job->id} «{$job->name}» пропущено: предыдущий бекап ещё выполняется.");

                    return;
                }

                $backup = $job->backups()->create(['status' => Backup::STATUS_PENDING, 'trigger' => Backup::TRIGGER_SCHEDULE]);
                RunBackup::dispatch($backup);
                $queued++;
            });

        $this->info("Поставлено в очередь бекапов: {$queued}.");

        return self::SUCCESS;
    }

    /**
     * Бекапы, «выполняющиеся» дольше предела (воркер убит, очередь потеряла задание), считаем упавшими.
     */
    private function failStale(): void
    {
        $limit = (int) config('backup.timeout') + 600;

        Backup::query()
            ->whereIn('status', Backup::ACTIVE_STATUSES)
            ->where('created_at', '<', now()->subSeconds($limit))
            ->get()
            ->each(function (Backup $backup) {
                $backup->deleteFile();
                $backup->forceFill([
                    'status' => Backup::STATUS_FAILED,
                    'file_path' => null,
                    'error' => 'Бекап не завершился за отведённое время (воркер остановлен или задание потеряно).',
                    'finished_at' => now(),
                ])->save();
            });
    }
}
