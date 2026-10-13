<?php

namespace Tests\Support;

use App\Models\Server;
use App\Services\Ssh\SshConnectorInterface;
use Throwable;

class FakeSshConnector implements SshConnectorInterface
{
    /** @var list<array{server: int, command: string}> */
    public array $calls = [];

    /**
     * @param  array{exit_code: int|null, stdout: string, stderr: string}  $result
     */
    public function __construct(
        public array $result = ['exit_code' => 0, 'stdout' => "Linux test 6.1\n up 3 days", 'stderr' => ''],
        public ?Throwable $throw = null,
        public ?string $rememberFingerprint = null,
    ) {}

    public function exec(Server $server, string $command, ?int $timeout = null): array
    {
        $this->calls[] = ['server' => $server->id, 'command' => $command];

        if ($this->throw) {
            throw $this->throw;
        }
        // имитация TOFU: запоминаем отпечаток при первом успешном подключении
        if ($this->rememberFingerprint && $server->host_fingerprint === null) {
            $server->forceFill(['host_fingerprint' => $this->rememberFingerprint])->save();
        }

        return $this->result;
    }
}
