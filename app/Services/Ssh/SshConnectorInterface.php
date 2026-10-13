<?php

namespace App\Services\Ssh;

use App\Exceptions\Ssh\HostFingerprintChangedException;
use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Server;

interface SshConnectorInterface
{
    /**
     * Выполняет команду на сервере. При первом успешном подключении запоминает отпечаток хоста (TOFU),
     * при последующих — сверяет с ним.
     *
     * $stdin — данные на вход команде (например, пароль БД: так он не попадает в аргументы процесса на сервере).
     *
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     *
     * @throws HostFingerprintChangedException
     * @throws SshConnectionException
     */
    public function exec(Server $server, string $command, ?int $timeout = null, ?string $stdin = null): array;
}
