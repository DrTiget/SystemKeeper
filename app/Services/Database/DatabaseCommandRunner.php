<?php

namespace App\Services\Database;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\DatabaseConnection;
use App\Services\Ssh\SshConnectorInterface;
use Symfony\Component\Process\Process;

/**
 * Выполняет shell-команду клиента БД: на сервере по SSH (via=ssh) или локально в SystemKeeper (via=direct).
 * Пароль передаётся через stdin — команда сама читает его первой строкой (см. DatabaseClient::withPassword).
 */
class DatabaseCommandRunner
{
    public function __construct(private SshConnectorInterface $ssh) {}

    /**
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     *
     * @throws SshConnectionException
     */
    public function run(DatabaseConnection $connection, string $command, int $timeout): array
    {
        $stdin = ($connection->password ?? '')."\n";

        if ($connection->via === DatabaseConnection::VIA_SSH) {
            return $this->ssh->exec($connection->server, $command, $timeout, $stdin);
        }

        $process = Process::fromShellCommandline($command, timeout: $timeout);
        $process->setInput($stdin);
        $process->run();

        return [
            'exit_code' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /**
     * Как run(), но stdout отдаётся частями в $onOutput (дампы).
     *
     * @param  callable(string): void  $onOutput
     * @return array{exit_code: int|null, stderr: string}
     *
     * @throws SshConnectionException
     */
    public function stream(DatabaseConnection $connection, string $command, callable $onOutput, int $timeout): array
    {
        $stdin = ($connection->password ?? '')."\n";

        if ($connection->via === DatabaseConnection::VIA_SSH) {
            return $this->ssh->stream($connection->server, $command, $onOutput, $timeout, $stdin);
        }

        $stderr = '';
        $process = Process::fromShellCommandline($command, timeout: $timeout);
        $process->setInput($stdin);
        $process->run(function (string $type, string $data) use ($onOutput, &$stderr) {
            $type === Process::OUT ? $onOutput($data) : $stderr .= $data;
        });

        return ['exit_code' => $process->getExitCode(), 'stderr' => $stderr];
    }
}
