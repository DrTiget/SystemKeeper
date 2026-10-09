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
        /** @var (callable(string, array<string, string>): (array{chunks?: list<string>, exit_code?: int|null, stderr?: string}|null))|null */
        public $handler = null,
    ) {}

    public function stream(string $command, array $env, callable $onChunk, ?int $timeout = null): array
    {
        $this->calls[] = ['command' => $command, 'env' => $env];

        // Обработчик может ответить на конкретную команду; null — ответ по умолчанию
        $scripted = $this->handler ? ($this->handler)($command, $env) : null;
        if ($scripted !== null) {
            foreach ($scripted['chunks'] ?? [] as $chunk) {
                $onChunk($chunk);
            }

            return ['exit_code' => $scripted['exit_code'] ?? 0, 'stderr' => $scripted['stderr'] ?? ''];
        }

        foreach ($this->chunks as $chunk) {
            $onChunk($chunk);
        }

        return $this->result;
    }
}
