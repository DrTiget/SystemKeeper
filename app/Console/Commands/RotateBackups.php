<?php

namespace App\Console\Commands;

use App\Models\BackupJob;
use App\Services\Backup\BackupRotator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('backups:rotate {--job= : id задания (по умолчанию все с правилами ротации)} {--dry-run : только показать, что будет удалено}')]
#[Description('Удаляет устаревшие бекапы (файлы, копии в хранилищах и записи) по правилам ротации заданий')]
class RotateBackups extends Command
{
    public function handle(BackupRotator $rotator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $failed = false;

        BackupJob::query()
            ->when($this->option('job'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->each(function (BackupJob $job) use ($rotator, $dryRun, &$failed) {
                if (! $rotator->hasRules($job)) {
                    return;
                }

                $result = $rotator->rotate($job, $dryRun);
                $verb = $dryRun ? 'будет удалено' : 'удалено';
                $this->line("Задание #{$job->id} «{$job->name}»: {$verb} ".count($result['deleted']).', остаётся '.$result['kept'].($result['deleted'] ? ' (id: '.implode(', ', $result['deleted']).')' : '').'.');

                foreach ($result['errors'] as $error) {
                    $failed = true;
                    $this->error($error);
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
