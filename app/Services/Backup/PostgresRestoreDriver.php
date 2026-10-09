<?php

namespace App\Services\Backup;

use RuntimeException;

class PostgresRestoreDriver extends RestoreDriver
{
    /** Ошибки, не говорящие о порче дампа: в тестовой среде нет ролей и расширений боевого сервера */
    private const IGNORED_ERRORS = [
        '/role "[^"]*" does not exist/',
        '/must be owner of/',
        '/permission denied to (?:create|set) /',
    ];

    public function recreate(array $target, string $database, int $timeout): void
    {
        $this->sql($target, 'postgres', "DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)", $timeout);
        $this->sql($target, 'postgres', "CREATE DATABASE \"{$database}\"", $timeout);
    }

    public function restore(array $target, string $database, string $file, int $timeout, array &$log): void
    {
        // Без ON_ERROR_STOP psql доходит до конца дампа; ошибки собираем из stderr и отфильтровываем безвредные
        $script = 'zstd -dc '.self::quote($file).' | '.$this->client($target, $database).' --quiet';
        $result = $this->exec('bash -o pipefail -c '.escapeshellarg($script), $this->env($target), $timeout);

        $errors = [];
        $ignored = 0;
        foreach (preg_split('/\R/', $result['stderr']) as $line) {
            if (! str_contains($line, 'ERROR:')) {
                continue;
            }
            $skip = false;
            foreach (self::IGNORED_ERRORS as $pattern) {
                $skip = $skip || preg_match($pattern, $line) === 1;
            }
            $skip ? $ignored++ : $errors[] = trim($line);
        }

        if ($ignored > 0) {
            $log[] = "Проигнорировано безвредных ошибок (роли и права): {$ignored}.";
        }
        if ($errors !== []) {
            throw new RuntimeException('Ошибки при восстановлении: '.implode(' | ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' …(+'.(count($errors) - 5).')' : ''));
        }
    }

    public function countTables(array $target, string $database, int $timeout): int
    {
        return (int) $this->scalar($target, $database, "SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema')", $timeout);
    }

    public function scalar(array $target, string $database, string $query, int $timeout): ?string
    {
        $out = $this->sql($target, $database, $query, $timeout, tuples: true)['stdout'];
        $first = trim(strtok($out, "\n") ?: '');

        return $first === '' ? null : trim(explode('|', $first)[0]);
    }

    public function drop(array $target, string $database, int $timeout): void
    {
        $this->sql($target, 'postgres', "DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)", $timeout);
    }

    /**
     * @return array{stdout: string, stderr: string}
     */
    private function sql(array $target, string $database, string $query, int $timeout, bool $tuples = false): array
    {
        $command = $this->client($target, $database).' -v ON_ERROR_STOP=1'.($tuples ? ' --tuples-only --no-align' : '').' --command='.escapeshellarg($query);

        return $this->exec($command, $this->env($target), $timeout);
    }

    private function client(array $target, string $database): string
    {
        $args = ['psql', '--no-password', '--host='.$target['host']];
        if ($target['port']) {
            $args[] = '--port='.$target['port'];
        }
        if ($target['username'] !== '') {
            $args[] = '--username='.$target['username'];
        }
        $args[] = '--dbname='.$database;

        return implode(' ', array_map(self::quote(...), $args));
    }

    /** @return array<string, string> */
    private function env(array $target): array
    {
        return $target['password'] ? ['PGPASSWORD' => $target['password']] : [];
    }
}
