<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\BackupJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ротация бекапов задания. Правила складываются по «ИЛИ»: бекап хранится, если его защищает хотя бы одно.
 *
 *  - retention_count — N самых свежих успешных бекапов;
 *  - retention_days  — успешные бекапы не старше N суток;
 *  - keep_daily / keep_weekly / keep_monthly (GFS) — самый свежий бекап каждого из последних N дней
 *    (недель ISO, месяцев), в которых бекапы были.
 *
 * Самый свежий успешный бекап не удаляется никогда. Без правил ротации ничего не удаляется.
 * Неуспешные бекапы старше backup.failed_retention_days удаляются, если у задания есть хоть одно правило.
 * Выполняющиеся бекапы не затрагиваются.
 */
class BackupRotator
{
    public function __construct(private readonly BackupPurger $purger) {}

    public function hasRules(BackupJob $job): bool
    {
        return $job->retention_count || $job->retention_days || $job->keep_daily || $job->keep_weekly || $job->keep_monthly;
    }

    /**
     * Что будет удалено, ничего не удаляя (dry-run).
     *
     * @return Collection<int, Backup>
     */
    public function plan(BackupJob $job, ?Carbon $now = null): Collection
    {
        if (! $this->hasRules($job)) {
            return new Collection;
        }

        $now ??= now();

        /** @var Collection<int, Backup> $success свежие первыми */
        $success = $job->backups()->where('status', Backup::STATUS_SUCCESS)->get()
            ->sortByDesc(fn (Backup $b) => [$this->moment($b)->getTimestamp(), $b->id])->values();

        $keep = [];
        if ($success->isNotEmpty()) {
            $keep[$success->first()->id] = true;
        }

        if ($job->retention_count) {
            foreach ($success->take($job->retention_count) as $backup) {
                $keep[$backup->id] = true;
            }
        }

        if ($job->retention_days) {
            $border = $now->copy()->subDays($job->retention_days);
            foreach ($success as $backup) {
                if ($this->moment($backup)->gte($border)) {
                    $keep[$backup->id] = true;
                }
            }
        }

        foreach ([
            [$job->keep_daily, 'Y-m-d'],
            [$job->keep_weekly, 'o-\WW'],
            [$job->keep_monthly, 'Y-m'],
        ] as [$limit, $format]) {
            $this->keepNewestPerPeriod($success, (int) $limit, $format, $keep);
        }

        $delete = $success->reject(fn (Backup $b) => isset($keep[$b->id]));

        $failedBorder = $now->copy()->subDays((int) config('backup.failed_retention_days'));
        $failed = $job->backups()->where('status', Backup::STATUS_FAILED)->where('created_at', '<', $failedBorder)->get();

        return $delete->concat($failed)->values();
    }

    /**
     * Применяет ротацию. При $dryRun только возвращает план.
     *
     * @return array{dry_run: bool, kept: int, deleted: list<int>, errors: list<string>}
     */
    public function rotate(BackupJob $job, bool $dryRun = false, ?Carbon $now = null): array
    {
        $plan = $this->plan($job, $now);
        $deleted = [];
        $errors = [];

        foreach ($plan as $backup) {
            if ($dryRun) {
                $deleted[] = $backup->id;

                continue;
            }

            try {
                $this->purger->purge($backup);
                $deleted[] = $backup->id;
            } catch (BackupPurgeException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'dry_run' => $dryRun,
            'kept' => $job->backups()->count() - ($dryRun ? count($deleted) : 0),
            'deleted' => $deleted,
            'errors' => $errors,
        ];
    }

    /**
     * @param  Collection<int, Backup>  $success  от свежих к старым
     * @param  array<int, true>  $keep
     */
    private function keepNewestPerPeriod(Collection $success, int $limit, string $format, array &$keep): void
    {
        if ($limit < 1) {
            return;
        }

        $seen = [];
        foreach ($success as $backup) {
            $period = $this->moment($backup)->format($format);
            if (isset($seen[$period])) {
                continue;
            }
            if (count($seen) >= $limit) {
                break;
            }
            $seen[$period] = true;
            $keep[$backup->id] = true;
        }
    }

    private function moment(Backup $backup): Carbon
    {
        return ($backup->finished_at ?? $backup->created_at)->copy();
    }
}
