<?php

namespace App\Services\Backup;

interface LocalCommandRunner
{
    /**
     * Выполняет команду на этом хосте (режим «напрямую»), отдавая stdout кусками в $onChunk.
     *
     * @param  array<string, string>  $env
     * @param  callable(string): void  $onChunk
     * @return array{exit_code: int|null, stderr: string}
     */
    public function stream(string $command, array $env, callable $onChunk, ?int $timeout = null): array;
}
