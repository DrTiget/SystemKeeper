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
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     *
     * @throws HostFingerprintChangedException
     * @throws SshConnectionException
     */
    public function exec(Server $server, string $command, ?int $timeout = null): array;
}
