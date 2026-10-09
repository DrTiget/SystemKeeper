<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Операции над временной базой на тестовом сервере. Всё выполняется клиентами БД в контейнере app
 * через LocalCommandRunner; пароль идёт в окружение процесса, а не в командную строку.
 *
 * @phpstan-type Target array{host: string, port: int|null, username: string, password: string|null, ssl_verify: bool}
 */
abstract class RestoreDriver
{
    public function __construct(protected readonly LocalCommandRunner $runner) {}

    /** Удалить (если осталась от прошлого сбоя) и создать пустую базу. */
    abstract public function recreate(array $target, string $database, int $timeout): void;

    /**
     * Развернуть дамп из сжатого файла.
     *
     * @param  list<string>  $log
     */
    abstract public function restore(array $target, string $database, string $file, int $timeout, array &$log): void;

    abstract public function countTables(array $target, string $database, int $timeout): int;

    /** Первое значение первой строки результата SELECT. */
    abstract public function scalar(array $target, string $database, string $query, int $timeout): ?string;

    abstract public function drop(array $target, string $database, int $timeout): void;

    /**
     * @param  array<string, string>  $env
     * @return array{stdout: string, stderr: string}
     *
     * @throws RuntimeException если код выхода не 0
     */
    protected function exec(string $command, array $env, int $timeout, bool $allowFail = false): array
    {
        $stdout = '';
        $result = $this->runner->stream($command, $env, function (string $chunk) use (&$stdout) {
            if (strlen($stdout) < 1_000_000) {
                $stdout .= $chunk;
            }
        }, $timeout);

        if ($result['exit_code'] !== 0 && ! $allowFail) {
            $code = $result['exit_code'] ?? '—';
            throw new RuntimeException("Команда завершилась с кодом {$code}: ".trim(mb_substr($result['stderr'], 0, 2000)));
        }

        return ['stdout' => $stdout, 'stderr' => $result['stderr'], 'exit_code' => $result['exit_code']];
    }

    protected static function quote(string $arg): string
    {
        return preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $arg) ? $arg : escapeshellarg($arg);
    }
}
