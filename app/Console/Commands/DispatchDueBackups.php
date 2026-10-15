<?php

namespace App\Console\Commands;

use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\DatabaseConnection;
use App\Models\ServerBackupSettings;
use App\Services\System\Updater;
use Cron\CronExpression;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

#[Signature('backups:dispatch-due')]
#[Description('Поставить в очередь бекапы, у которых по расписанию подошло время (планировщик, каждую минуту)')]
class DispatchDueBackups extends Command
{
    public function handle(Updater $updater): int
    {
        // во время обновления сервисы перезапускаются — бекап по расписанию не начинаем
        if ($updater->locked()) {
            $this->line('Идёт обновление SystemKeeper — плановые бекапы пропущены');

            return self::SUCCESS;
        }

        $tz = config('backup.timezone');
        $now = now()->setTimezone($tz)->startOfMinute();

        $sources = [
            ...ServerBackupSettings::query()->where('enabled', true)->with('server')->get()
                ->filter(fn (ServerBackupSettings $s) => $s->server && $s->paths !== [])
                ->map(fn (ServerBackupSettings $s) => [$s->server, $s->schedule]),
            ...DatabaseConnection::query()->where('backup_enabled', true)->get()
                ->map(fn (DatabaseConnection $d) => [$d, $d->schedule]),
        ];

        foreach ($sources as [$source, $schedule]) {
            try {
                if ($this->isDue((string) $schedule, $now, $tz) && $this->start($source, $now)) {
                    $this->line("Запущен бекап: {$source->name}");
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }

    private function isDue(string $schedule, Carbon $now, string $tz): bool
    {
        return CronExpression::isValidExpression($schedule) && (new CronExpression($schedule))->isDue($now, $tz);
    }

    /** Не запускаем, если бекап источника ещё идёт или в эту минуту уже запущен. */
    private function start(Model $source, Carbon $now): bool
    {
        $busy = Backup::query()->whereMorphedTo('source', $source)
            ->where(fn ($q) => $q->whereIn('status', [Backup::QUEUED, Backup::RUNNING])
                ->orWhere(fn ($q) => $q->where('trigger', Backup::TRIGGER_SCHEDULE)->where('created_at', '>=', $now->clone()->utc())))
            ->exists();
        if ($busy) {
            return false;
        }

        $backup = new Backup(['trigger' => Backup::TRIGGER_SCHEDULE, 'status' => Backup::QUEUED]);
        $backup->source()->associate($source)->save();
        RunBackup::dispatch($backup);

        return true;
    }
}
