<?php

namespace Tests\Support;

use App\Models\Server;
use App\Services\Ssh\SshConnectorInterface;
use Throwable;

class FakeSshConnector implements SshConnectorInterface
{
    /** @var list<array{server: int, command: string, stdin: string|null}> */
    public array $calls = [];

    /**
     * Ответы по подстроке команды — для сценариев из нескольких команд (команда перед бекапом, архив).
     *
     * @var array<string, array{exit_code: int|null, stdout: string, stderr: string}>
     */
    public array $results = [];

    /**
     * @param  array{exit_code: int|null, stdout: string, stderr: string}  $result
     */
    public function __construct(
        public array $result = ['exit_code' => 0, 'stdout' => "Linux test 6.1\n up 3 days", 'stderr' => ''],
        public ?Throwable $throw = null,
        public ?string $rememberFingerprint = null,
    ) {}

    public function exec(Server $server, string $command, ?int $timeout = null, ?string $stdin = null): array
    {
        $this->calls[] = ['server' => $server->id, 'command' => $command, 'stdin' => $stdin];

        if ($this->throw) {
            throw $this->throw;
        }
        // имитация TOFU: запоминаем отпечаток при первом успешном подключении
        if ($this->rememberFingerprint && $server->host_fingerprint === null) {
            $server->forceFill(['host_fingerprint' => $this->rememberFingerprint])->save();
        }

        foreach ($this->results as $needle => $result) {
            if (str_contains($command, $needle)) {
                return $result;
            }
        }

        return $this->result;
    }

    public function stream(Server $server, string $command, callable $onOutput, ?int $timeout = null, ?string $stdin = null): array
    {
        $result = $this->exec($server, $command, $timeout, $stdin);
        if ($result['stdout'] !== '') {
            $onOutput($result['stdout']);
        }

        return ['exit_code' => $result['exit_code'], 'stderr' => $result['stderr']];
    }
}
