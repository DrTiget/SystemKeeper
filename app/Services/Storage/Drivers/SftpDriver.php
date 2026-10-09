<?php

namespace App\Services\Storage\Drivers;

use App\Exceptions\Ssh\HostFingerprintChangedException;
use App\Exceptions\Ssh\SshConnectionException;
use App\Exceptions\Storage\StorageException;
use App\Models\Server;
use App\Models\StorageTarget;
use App\Services\Ssh\SshConnectorInterface;
use App\Services\Storage\Drivers\Concerns\GuardsBasePath;

/**
 * Хранилище на другом сервере (учётные данные, ключ и отпечаток хоста — от записи Server).
 * Файл подаётся на stdin удалённой команды системным ssh прямо из файла (без загрузки в память),
 * затем на удалённой стороне считается sha256 и только после совпадения файл переименовывается в итоговый.
 */
class SftpDriver implements StorageDriver
{
    use GuardsBasePath;

    public function __construct(
        private readonly StorageTarget $target,
        private readonly SshConnectorInterface $ssh,
    ) {}

    public function upload(string $localPath, string $remotePath): array
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new StorageException("Файл не найден или недоступен: {$localPath}");
        }
        $this->assertInsideBase($this->target, $remotePath);

        $sha256 = hash_file('sha256', $localPath);
        $tmp = $remotePath.'.part';
        $dir = dirname($remotePath);

        $this->withRetries(function () use ($localPath, $dir, $tmp) {
            $result = $this->run(
                'mkdir -p '.escapeshellarg($dir).' && umask 077 && cat > '.escapeshellarg($tmp),
                (int) config('storage_targets.sftp.upload_timeout'),
                $localPath,
            );
            $this->assertOk($result, 'Не удалось записать файл на сервер');
        });

        $result = $this->run($this->sha256Command($tmp), (int) config('storage_targets.sftp.command_timeout'));
        $remote = strtolower(substr(trim($result['stdout']), 0, 64));
        if ($result['exit_code'] !== 0 || ! preg_match('/^[0-9a-f]{64}$/', $remote)) {
            $this->discard($tmp);
            throw new StorageException('Не удалось посчитать sha256 на сервере: '.($this->tail($result['stderr']) ?: 'код '.($result['exit_code'] ?? '—')));
        }
        if ($remote !== $sha256) {
            $this->discard($tmp);
            throw new StorageException("Контрольная сумма не совпала: локально {$sha256}, на сервере {$remote}.");
        }

        $result = $this->run('mv -f '.escapeshellarg($tmp).' '.escapeshellarg($remotePath), (int) config('storage_targets.sftp.command_timeout'));
        $this->assertOk($result, 'Не удалось переименовать загруженный файл');

        return ['md5' => null, 'sha256' => $sha256];
    }

    public function delete(string $remotePath): void
    {
        $this->assertInsideBase($this->target, $remotePath);

        $result = $this->run('rm -f '.escapeshellarg($remotePath), (int) config('storage_targets.sftp.command_timeout'));
        $this->assertOk($result, 'Не удалось удалить файл на сервере');
    }

    public function check(): array
    {
        $base = $this->target->base_path;
        $probe = rtrim($base, '/').'/.systemkeeper-check-'.bin2hex(random_bytes(6));

        $result = $this->run(
            'mkdir -p '.escapeshellarg($base).' && echo ok > '.escapeshellarg($probe).' && rm -f '.escapeshellarg($probe)
            .' && df -Pk '.escapeshellarg($base).' | tail -n 1',
            (int) config('storage_targets.sftp.command_timeout'),
        );
        $this->assertOk($result, "Папка {$base} недоступна для записи");

        // df -P: Filesystem 1024-blocks Used Available Capacity Mounted on
        $cols = preg_split('/\s+/', trim($result['stdout']));
        if (count($cols) < 6 || ! ctype_digit($cols[1]) || ! ctype_digit($cols[2]) || ! ctype_digit($cols[3])) {
            return ['total_space' => null, 'used_space' => null, 'free_space' => null];
        }

        return ['total_space' => (int) $cols[1] * 1024, 'used_space' => (int) $cols[2] * 1024, 'free_space' => (int) $cols[3] * 1024];
    }

    private function sha256Command(string $path): string
    {
        $p = escapeshellarg($path);

        // sha256sum (GNU/BusyBox) или shasum (macOS/BSD)
        return "(sha256sum {$p} 2>/dev/null || shasum -a 256 {$p}) | cut -d' ' -f1";
    }

    private function discard(string $path): void
    {
        try {
            $this->run('rm -f '.escapeshellarg($path), 60);
        } catch (StorageException) {
            // временный файл не удалился — сообщаем об исходной проблеме
        }
    }

    /**
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     *
     * @throws StorageException
     */
    private function run(string $command, int $timeout, ?string $stdinFile = null): array
    {
        $server = $this->server();
        $stdout = '';

        try {
            $result = $this->ssh->stream($server, $command, function (string $chunk) use (&$stdout) {
                if (strlen($stdout) < 65536) {
                    $stdout .= $chunk;
                }
            }, $timeout, $stdinFile);
        } catch (HostFingerprintChangedException|SshConnectionException $e) {
            throw new StorageException($e->getMessage());
        }

        return ['exit_code' => $result['exit_code'], 'stdout' => $stdout, 'stderr' => $result['stderr']];
    }

    /** Повторяет при сбое соединения; ошибки самой команды (нехватка места, права) повторять бессмысленно. */
    private function withRetries(callable $operation): void
    {
        $attempts = max((int) config('storage_targets.sftp.attempts'), 1);

        for ($i = 1; ; $i++) {
            try {
                $operation();

                return;
            } catch (StorageException $e) {
                if ($i >= $attempts || ! $this->isConnectionProblem($e)) {
                    throw $e;
                }
                usleep((int) config('storage_targets.sftp.backoff_ms') * 1000 * (2 ** ($i - 1)));
            }
        }
    }

    private function isConnectionProblem(StorageException $e): bool
    {
        return $e->status === null
            && (str_starts_with($e->getMessage(), 'Ошибка SSH') || str_starts_with($e->getMessage(), 'Не удалось подключиться'));
    }

    /** @param  array{exit_code: int|null, stdout: string, stderr: string}  $result */
    private function assertOk(array $result, string $what): void
    {
        if ($result['exit_code'] !== 0) {
            throw new StorageException($what.': '.($this->tail($result['stderr']) ?: 'код выхода '.($result['exit_code'] ?? '—')));
        }
    }

    private function tail(string $stderr): string
    {
        return mb_substr(trim($stderr), -300);
    }

    private function server(): Server
    {
        $server = $this->target->server;
        if ($server === null) {
            throw new StorageException('Сервер хранилища не найден.');
        }

        return $server;
    }
}
