<?php

namespace App\Services\Backup;

use App\Events\BackupFailed;
use App\Events\BackupSucceeded;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Выполняет один бекап: стримит вывод конвейера прямо в локальный файл, считая размер и sha256/md5 на лету.
 */
class BackupRunner
{
    public function __construct(
        private readonly SshConnectorInterface $ssh,
        private readonly LocalCommandRunner $local,
        private readonly BackupCommandBuilder $builder,
    ) {}

    public function run(Backup $backup): Backup
    {
        $backup->loadMissing('job.server');
        $job = $backup->job;
        $command = $this->builder->build($job);

        $disk = Storage::disk(config('backup.disk'));
        $relative = $this->relativePath($backup, $job);
        $disk->makeDirectory(dirname($relative));
        $absolute = $disk->path($relative);

        $backup->forceFill([
            'status' => Backup::STATUS_RUNNING,
            'started_at' => now(),
            'finished_at' => null,
            'error' => null,
            'log' => null,
            'file_path' => $relative,
        ])->save();

        $handle = null;

        try {
            $handle = fopen($absolute, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Не удалось создать файл бекапа.');
            }

            $sha256 = hash_init('sha256');
            $md5 = hash_init('md5');
            $size = 0;
            $onChunk = function (string $chunk) use ($handle, $sha256, $md5, &$size): void {
                if (fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Не удалось записать данные в файл бекапа (нет места на диске?).');
                }
                hash_update($sha256, $chunk);
                hash_update($md5, $chunk);
                $size += strlen($chunk);
            };

            $timeout = (int) config('backup.timeout');
            $result = $this->isDirect($job)
                ? $this->local->stream($command->command, $command->env, $onChunk, $timeout)
                : $this->ssh->stream($job->server, $command->forRemote(), $onChunk, $timeout);

            if (! fclose($handle)) {
                $handle = null;
                throw new RuntimeException('Не удалось сохранить файл бекапа.');
            }
            $handle = null;

            $log = $this->clean($result['stderr'], $command->secrets);

            if ($result['exit_code'] !== 0) {
                $code = $result['exit_code'] ?? '—';
                throw new BackupFailedException("Команда завершилась с кодом {$code}".($log !== '' ? ": {$log}" : '.'), $log);
            }
            if ($size === 0) {
                throw new BackupFailedException('Команда не вернула данных.', $log);
            }

            $backup->forceFill([
                'status' => Backup::STATUS_SUCCESS,
                'size_bytes' => $size,
                'sha256' => hash_final($sha256),
                'md5' => hash_final($md5),
                'finished_at' => now(),
                'log' => $log !== '' ? $log : null,
            ])->save();

            event(new BackupSucceeded($backup));
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->fail($backup, $e, $command->secrets);
        }

        return $backup;
    }

    /**
     * Помечает бекап упавшим и удаляет недописанный файл. Используется и при аварийном завершении задания очереди.
     *
     * @param  list<string>  $secrets
     */
    public function fail(Backup $backup, Throwable $e, array $secrets = []): void
    {
        $backup->deleteFile();

        $backup->forceFill([
            'status' => Backup::STATUS_FAILED,
            'file_path' => null,
            'size_bytes' => null,
            'sha256' => null,
            'md5' => null,
            'finished_at' => now(),
            'error' => $this->clean($e->getMessage(), $secrets),
            'log' => $e instanceof BackupFailedException && $e->log !== '' ? $e->log : null,
        ])->save();

        event(new BackupFailed($backup));
    }

    private function isDirect(BackupJob $job): bool
    {
        return $job->isDatabase() && $job->db_mode === BackupJob::DB_MODE_DIRECT;
    }

    /**
     * storage/app/backups/{server}/{job}/{дата}-{id}.{ext}
     */
    private function relativePath(Backup $backup, BackupJob $job): string
    {
        return sprintf(
            '%d/%d/%s-%d.%s',
            $job->server_id,
            $job->id,
            now()->format('Ymd-His'),
            $backup->id,
            $this->builder->extension($job),
        );
    }

    /**
     * @param  list<string>  $secrets
     */
    private function clean(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            $text = str_replace($secret, '***', $text);
        }
        $text = mb_convert_encoding(trim($text), 'UTF-8', 'UTF-8');
        $limit = (int) config('backup.log_limit');

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }
}
