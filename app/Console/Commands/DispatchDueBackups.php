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
#[Description('Поставить в очередь бекапы, у которых по расписанию подошло время или запуск пропущен (планировщик, каждую минуту)')]
class DispatchDueBackups extends Command
{
    /** Пропущенный запуск (обновление, простой сервиса) догоняется, если с его времени прошло не больше, часов */
    public const CATCH_UP_HOURS = 6;

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
                ->map(fn (ServerBackupSettings $s) => [$s->server, $s->schedule, $s->updated_at]),
            ...DatabaseConnection::query()->where('backup_enabled', true)->get()
                ->map(fn (DatabaseConnection $d) => [$d, $d->schedule, $d->updated_at]),
        ];

        foreach ($sources as [$source, $schedule, $changedAt]) {
            try {
                $due = $this->lastDue((string) $schedule, $now, $tz);
                // расписание включили или поменяли уже после срока — прошлый срок не догоняем
                if ($due === null || ($changedAt && $changedAt->greaterThan($due))) {
                    continue;
                }
                if ($this->start($source, $due)) {
                    $late = $due->lessThan($now) ? ' (пропущенный запуск '.$due->format('d.m H:i').')' : '';
                    $this->line("Запущен бекап: {$source->name}{$late}");
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }

    /** Последний срок по расписанию (включая текущую минуту), если он был не раньше CATCH_UP_HOURS назад. */
    private function lastDue(string $schedule, Carbon $now, string $tz): ?Carbon
    {
        if (! CronExpression::isValidExpression($schedule)) {
            return null;
        }
        $due = Carbon::instance((new CronExpression($schedule))->getPreviousRunDate($now, 0, true, $tz));

        return $due->greaterThanOrEqualTo($now->clone()->subHours(self::CATCH_UP_HOURS)) ? $due : null;
    }

    /** Не запускаем, если бекап источника ещё идёт или по этому сроку уже запущен. */
    private function start(Model $source, Carbon $due): bool
    {
        $busy = Backup::query()->whereMorphedTo('source', $source)
            ->where(fn ($q) => $q->whereIn('status', [Backup::QUEUED, Backup::RUNNING])
                ->orWhere(fn ($q) => $q->where('trigger', Backup::TRIGGER_SCHEDULE)->where('created_at', '>=', $due->clone()->utc())))
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
