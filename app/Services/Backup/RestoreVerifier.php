<?php

namespace App\Services\Backup;

use App\Events\BackupRestoreFailed;
use App\Jobs\VerifyBackupRestore;
use App\Models\Backup;
use App\Models\BackupJob;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Тестовое восстановление дампа БД во временную базу sk_restore_<id бекапа>: развернуть дамп, убедиться,
 * что ошибок нет и таблицы появились, при желании выполнить проверочный SELECT; затем базу удалить.
 *
 * Дамп всех баз (без db_name) не проверяется: он содержит CREATE DATABASE/USE и затронул бы настоящие базы.
 * Для MySQL дополнительно включён `--one-database`, а учётной записи достаточно прав только на `sk\_restore\_%`.
 */
class RestoreVerifier
{
    public function __construct(private readonly LocalCommandRunner $runner) {}

    /** Нужно ли проверять этот (успешный) бекап по настройкам задания. */
    public function isDue(Backup $backup): bool
    {
        $job = $backup->job;

        if (! $job->verify_restore || ! $job->isDatabase() || $backup->status !== Backup::STATUS_SUCCESS) {
            return false;
        }
        if (! $job->verify_every_n && ! $job->verify_every_days) {
            return true;
        }

        if ($job->verify_every_n) {
            $ordinal = $job->backups()->where('status', Backup::STATUS_SUCCESS)->where('id', '<=', $backup->id)->count();
            if ($ordinal % $job->verify_every_n === 0) {
                return true;
            }
        }

        if ($job->verify_every_days) {
            $last = $job->backups()
                ->where('id', '!=', $backup->id)
                ->whereNotNull('restore_status')
                ->where('restore_status', '!=', Backup::RESTORE_SKIPPED)
                ->get()
                ->map(fn (Backup $b) => $b->restore_checked_at ?? $b->updated_at)
                ->max();

            if ($last === null || $last->lte(now()->subDays($job->verify_every_days))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ставит проверку в очередь, если она положена по настройкам. Возвращает true, если поставлена.
     */
    public function dispatchIfDue(Backup $backup): bool
    {
        $backup->loadMissing('job');

        if (! $this->isDue($backup)) {
            return false;
        }

        return $this->dispatch($backup);
    }

    public function dispatch(Backup $backup): bool
    {
        $backup->forceFill(['restore_status' => Backup::RESTORE_PENDING, 'restore_checked_at' => null, 'restore_log' => null])->save();
        VerifyBackupRestore::dispatch($backup);

        return true;
    }

    /**
     * Выполняет проверку и сохраняет результат в бекап. Не бросает исключений: любой сбой — статус failed.
     */
    public function verify(Backup $backup): Backup
    {
        $backup->loadMissing('job');
        $job = $backup->job;
        $started = microtime(true);
        $log = [];

        $backup->forceFill(['restore_status' => Backup::RESTORE_RUNNING, 'restore_checked_at' => null, 'restore_log' => null])->save();

        try {
            $skip = $this->skipReason($job);
            if ($skip !== null) {
                return $this->finish($backup, Backup::RESTORE_SKIPPED, [$skip], $started);
            }
            if ($backup->file_path === null || ! $backup->fileExists()) {
                throw new RuntimeException('Файл бекапа не найден на диске.');
            }

            $driver = $job->type === BackupJob::TYPE_MYSQL ? new MysqlRestoreDriver($this->runner) : new PostgresRestoreDriver($this->runner);
            $target = $this->target($job);
            $database = 'sk_restore_'.$backup->id;
            $file = Storage::disk(config('backup.disk'))->path($backup->file_path);
            $timeout = (int) config('backup.restore.timeout');

            try {
                $driver->recreate($target, $database, $timeout);
                $log[] = "База {$database} создана.";

                $driver->restore($target, $database, $file, $timeout, $log);
                $log[] = 'Дамп развёрнут без ошибок.';

                $tables = $driver->countTables($target, $database, $timeout);
                if ($tables < 1) {
                    throw new RuntimeException('После восстановления в базе нет таблиц.');
                }
                $log[] = "Таблиц после восстановления: {$tables}.";

                if (filled($job->verify_query)) {
                    $value = $driver->scalar($target, $database, $job->verify_query, $timeout);
                    $log[] = 'Проверочный запрос вернул: '.($value ?? 'пусто').'.';
                    if ($job->verify_query_min !== null) {
                        if (! is_numeric($value) || (float) $value < $job->verify_query_min) {
                            throw new RuntimeException("Проверочный запрос вернул «{$value}», ожидалось не меньше {$job->verify_query_min}.");
                        }
                    }
                }
            } finally {
                try {
                    $driver->drop($target, $database, $timeout);
                    $log[] = "База {$database} удалена.";
                } catch (Throwable $e) {
                    $log[] = "Не удалось удалить базу {$database}: ".$e->getMessage();
                }
            }

            return $this->finish($backup, Backup::RESTORE_OK, $log, $started);
        } catch (Throwable $e) {
            $log[] = 'Ошибка: '.$e->getMessage();

            return $this->finish($backup, Backup::RESTORE_FAILED, $log, $started, $target['password'] ?? null);
        }
    }

    private function skipReason(BackupJob $job): ?string
    {
        if (! $job->db_name) {
            return 'Пропущено: дамп всех баз нельзя безопасно развернуть на тестовом сервере — укажите конкретную базу (db_name).';
        }
        if ($job->type === BackupJob::TYPE_POSTGRES && ! $job->restore_host) {
            return 'Пропущено: для PostgreSQL не задан тестовый сервер (restore_host).';
        }

        return null;
    }

    /**
     * @return array{host: string, port: int|null, username: string, password: string|null, ssl_verify: bool}
     */
    private function target(BackupJob $job): array
    {
        $default = $job->type === BackupJob::TYPE_MYSQL ? config('backup.restore.mysql') : [];

        if ($job->restore_host) {
            return [
                'host' => $job->restore_host,
                'port' => $job->restore_port,
                'username' => $job->restore_user ?? '',
                'password' => $job->restore_password,
                'ssl_verify' => (bool) ($default['ssl_verify'] ?? true),
            ];
        }

        return [
            'host' => (string) $default['host'],
            'port' => $default['port'] ?: null,
            'username' => (string) $default['username'],
            'password' => $default['password'] ?: null,
            'ssl_verify' => (bool) $default['ssl_verify'],
        ];
    }

    /**
     * @param  list<string>  $log
     */
    private function finish(Backup $backup, string $status, array $log, float $started, ?string $secret = null): Backup
    {
        $text = implode("\n", $log);
        $text .= sprintf("\nВремя проверки: %.1f с.", microtime(true) - $started);
        if ($secret) {
            $text = str_replace($secret, '***', $text);
        }
        $limit = (int) config('backup.log_limit');
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        $backup->forceFill([
            'restore_status' => $status,
            'restore_checked_at' => now(),
            'restore_log' => mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text,
        ])->save();

        if ($status === Backup::RESTORE_FAILED) {
            event(new BackupRestoreFailed($backup));
        }

        return $backup;
    }
}
