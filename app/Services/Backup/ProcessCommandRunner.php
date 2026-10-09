<?php

namespace App\Services\Backup;

use Symfony\Component\Process\Process;

class ProcessCommandRunner implements LocalCommandRunner
{
    /** Предел накапливаемого stderr, байт */
    private const STDERR_LIMIT = 1_000_000;

    public function stream(string $command, array $env, callable $onChunk, ?int $timeout = null): array
    {
        $process = Process::fromShellCommandline($command, null, $env, null, $timeout ?? (float) config('backup.timeout'));
        $stderr = '';

        try {
            $process->start();
            // Итерация без ITER_KEEP_OUTPUT не накапливает вывод в памяти
            foreach ($process as $type => $data) {
                if ($type === Process::OUT) {
                    $onChunk($data);
                } elseif (strlen($stderr) < self::STDERR_LIMIT) {
                    $stderr .= $data;
                }
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(5);
            }
        }

        return ['exit_code' => $process->getExitCode(), 'stderr' => $stderr];
    }
}
