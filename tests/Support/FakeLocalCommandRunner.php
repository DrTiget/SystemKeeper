<?php

namespace Tests\Support;

use App\Services\Backup\LocalCommandRunner;

class FakeLocalCommandRunner implements LocalCommandRunner
{
    /** @var list<array{command: string, env: array<string, string>}> */
    public array $calls = [];

    /**
     * @param  list<string>  $chunks
     * @param  array{exit_code: int|null, stderr: string}  $result
     */
    public function __construct(
        public array $chunks = [],
        public array $result = ['exit_code' => 0, 'stderr' => ''],
    ) {}

    public function stream(string $command, array $env, callable $onChunk, ?int $timeout = null): array
    {
        $this->calls[] = ['command' => $command, 'env' => $env];

        foreach ($this->chunks as $chunk) {
            $onChunk($chunk);
        }

        return $this->result;
    }
}
