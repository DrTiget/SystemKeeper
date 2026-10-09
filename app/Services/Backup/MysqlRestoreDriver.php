<?php

namespace App\Services\Backup;

class MysqlRestoreDriver extends RestoreDriver
{
    public function recreate(array $target, string $database, int $timeout): void
    {
        $this->sql($target, null, "DROP DATABASE IF EXISTS `{$database}`; CREATE DATABASE `{$database}`", $timeout);
    }

    public function restore(array $target, string $database, string $file, int $timeout, array &$log): void
    {
        // Дамп одной базы не содержит USE/CREATE DATABASE; на всякий случай вырезаем их, чтобы проверка
        // не могла затронуть другую базу. (Клиентский --one-database не годится: он пропускает дамп без USE целиком.)
        $script = 'zstd -dc '.self::quote($file)." | LC_ALL=C sed -E '/^(USE |CREATE DATABASE )/Id' | ".$this->client($target, $database);
        $result = $this->exec('bash -o pipefail -c '.escapeshellarg($script), $this->env($target), $timeout);

        if (trim($result['stderr']) !== '') {
            $log[] = 'Предупреждения клиента: '.trim(mb_substr($result['stderr'], 0, 1000));
        }
    }

    public function countTables(array $target, string $database, int $timeout): int
    {
        return (int) $this->scalar($target, $database, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$database}'", $timeout);
    }

    public function scalar(array $target, string $database, string $query, int $timeout): ?string
    {
        $out = $this->sql($target, $database, $query, $timeout, batch: true)['stdout'];
        $first = trim(strtok($out, "\n") ?: '');

        return $first === '' ? null : trim(explode("\t", $first)[0]);
    }

    public function drop(array $target, string $database, int $timeout): void
    {
        $this->sql($target, null, "DROP DATABASE IF EXISTS `{$database}`", $timeout);
    }

    /**
     * @return array{stdout: string, stderr: string}
     */
    private function sql(array $target, ?string $database, string $query, int $timeout, bool $batch = false): array
    {
        $command = $this->client($target, $database).($batch ? ' --batch --skip-column-names' : '').' --execute='.escapeshellarg($query);

        return $this->exec($command, $this->env($target), $timeout);
    }

    private function client(array $target, ?string $database): string
    {
        $args = ['mysql', '--host='.$target['host']];
        if ($target['port']) {
            $args[] = '--port='.$target['port'];
        }
        if ($target['username'] !== '') {
            $args[] = '--user='.$target['username'];
        }
        if (! $target['ssl_verify']) {
            $args[] = '--skip-ssl-verify-server-cert';
        }
        if ($database !== null) {
            $args[] = '--database='.$database;
        }

        return implode(' ', array_map(self::quote(...), $args));
    }

    /** @return array<string, string> */
    private function env(array $target): array
    {
        return $target['password'] ? ['MYSQL_PWD' => $target['password']] : [];
    }
}
