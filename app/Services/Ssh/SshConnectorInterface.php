<?php

namespace App\Services\Ssh;

use App\Exceptions\Ssh\HostFingerprintChangedException;
use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Server;
use phpseclib3\Net\SSH2;

interface SshConnectorInterface
{
    /**
     * Открывает авторизованную SSH-сессию. При первом подключении запоминает отпечаток хоста (TOFU).
     *
     * @throws HostFingerprintChangedException
     * @throws SshConnectionException
     */
    public function connect(Server $server): SSH2;

    /**
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     *
     * @throws HostFingerprintChangedException
     * @throws SshConnectionException
     */
    public function exec(Server $server, string $command, ?int $timeout = null): array;

    /**
     * Выполняет команду и отдаёт stdout кусками в $onChunk, не накапливая его в памяти (бинарно-безопасно).
     * Исключение из $onChunk прерывает выполнение и пробрасывается наружу.
     *
     * @param  callable(string): void  $onChunk
     * @return array{exit_code: int|null, stderr: string}
     *
     * @throws HostFingerprintChangedException
     * @throws SshConnectionException
     */
    public function stream(Server $server, string $command, callable $onChunk, ?int $timeout = null): array;
}
