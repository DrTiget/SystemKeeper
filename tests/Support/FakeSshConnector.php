<?php

namespace Tests\Support;

use App\Models\Server;
use App\Services\Ssh\SshConnectorInterface;
use LogicException;
use phpseclib3\Net\SSH2;
use Throwable;

class FakeSshConnector implements SshConnectorInterface
{
    /** @var array<int, array{server: int, command: string}> */
    public array $calls = [];

    /** @var list<string> куски stdout, которые отдаёт stream() */
    public array $streamChunks = [];

    /** @var array{exit_code: int|null, stderr: string} */
    public array $streamResult = ['exit_code' => 0, 'stderr' => ''];

    /**
     * @param  array{exit_code: int|null, stdout: string, stderr: string}  $result
     */
    public function __construct(
        public array $result = ['exit_code' => 0, 'stdout' => "Linux test 6.1\n up 3 days", 'stderr' => ''],
        public ?Throwable $throw = null,
        public ?string $rememberFingerprint = null,
    ) {}

    public function connect(Server $server): SSH2
    {
        throw new LogicException('Фейковый коннектор не открывает сессий.');
    }

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

    public function stream(Server $server, string $command, callable $onChunk, ?int $timeout = null, ?string $stdinFile = null): array
    {
        $this->calls[] = ['server' => $server->id, 'command' => $command, 'stdin_file' => $stdinFile];

        if ($this->throw) {
            throw $this->throw;
        }
        foreach ($this->streamChunks as $chunk) {
            $onChunk($chunk);
        }

        return $this->streamResult;
    }
}
