<?php

namespace App\Services\Backup;

use App\Models\BackupJob;
use InvalidArgumentException;

/**
 * Собирает конвейер `источник | zstd`. Весь вывод идёт в stdout, ничего не пишется на диск сервера.
 * Обёртка `bash -o pipefail` нужна, чтобы падение tar/mysqldump не маскировалось успешным zstd.
 */
class BackupCommandBuilder
{
    public function build(BackupJob $job): BackupCommand
    {
        return match ($job->type) {
            BackupJob::TYPE_FILES => $this->files($job),
            BackupJob::TYPE_MYSQL => $this->mysql($job),
            BackupJob::TYPE_POSTGRES => $this->postgres($job),
            default => throw new InvalidArgumentException("Неизвестный тип бекапа: {$job->type}"),
        };
    }

    public function extension(BackupJob $job): string
    {
        return $job->type === BackupJob::TYPE_FILES ? 'tar.zst' : 'sql.zst';
    }

    private function files(BackupJob $job): BackupCommand
    {
        $args = ['tar', '-C', '/', '--warning=no-file-changed', '--warning=no-file-removed', '-cf', '-'];
        foreach ($job->excludes ?? [] as $pattern) {
            $args[] = '--exclude='.ltrim($pattern, '/');
        }
        $args[] = '--';
        foreach ($job->paths ?? [] as $path) {
            $args[] = ltrim($path, '/') ?: '.';
        }

        return new BackupCommand($this->pipeline($this->quote($args), $job));
    }

    private function mysql(BackupJob $job): BackupCommand
    {
        $args = ['mysqldump', '--single-transaction', '--quick', '--routines', '--triggers', '--events'];
        $args = [...$args, ...$this->connectionArgs($job, user: '--user=', host: '--host=', port: '--port=')];
        array_push($args, ...($job->db_name ? [$job->db_name] : ['--all-databases']));

        return new BackupCommand(
            $this->pipeline($this->quote($args), $job),
            $job->db_password ? ['MYSQL_PWD' => $job->db_password] : [],
            array_filter([$job->db_password]),
        );
    }

    private function postgres(BackupJob $job): BackupCommand
    {
        $args = [$job->db_name ? 'pg_dump' : 'pg_dumpall', '--no-password'];
        $args = [...$args, ...$this->connectionArgs($job, user: '--username=', host: '--host=', port: '--port=')];
        if ($job->db_name) {
            $args[] = '--dbname='.$job->db_name;
        }

        return new BackupCommand(
            $this->pipeline($this->quote($args), $job),
            $job->db_password ? ['PGPASSWORD' => $job->db_password] : [],
            array_filter([$job->db_password]),
        );
    }

    /**
     * @return list<string>
     */
    private function connectionArgs(BackupJob $job, string $user, string $host, string $port): array
    {
        return array_values(array_filter([
            $job->db_host ? $host.$job->db_host : null,
            $job->db_port ? $port.$job->db_port : null,
            $job->db_user ? $user.$job->db_user : null,
        ]));
    }

    /**
     * @param  list<string>  $args
     */
    private function quote(array $args): string
    {
        // Безопасные слова оставляем как есть — команда читается в логах и тестах
        return implode(' ', array_map(
            fn (string $arg) => preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $arg) ? $arg : escapeshellarg($arg),
            $args,
        ));
    }

    private function pipeline(string $source, BackupJob $job): string
    {
        $level = max(1, min(19, (int) ($job->compression_level ?: config('backup.compression_level'))));
        $script = "{$source} | zstd -T0 -{$level} -q -c";

        return 'bash -o pipefail -c '.escapeshellarg($script);
    }
}
