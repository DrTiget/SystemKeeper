<?php

namespace App\Services\Ssh;

use App\Exceptions\Ssh\SshConnectionException;
use Throwable;

/**
 * Выполняет команду через системный клиент OpenSSH (proc_open) и отдаёт stdout кусками.
 *
 * Крипто и копирование делает сам ssh на нативном коде, поэтому поток в разы быстрее phpseclib.
 * Закрытый ключ, known_hosts и askpass-скрипт живут во временном каталоге 0700 и удаляются всегда.
 * Хост проверяется строго: known_hosts содержит единственный ключ — уже проверенный по сохранённому отпечатку.
 */
class OpenSshStreamRunner
{
    /** Сколько читать из канала за раз */
    private const CHUNK = 1048576;

    /** Предел накапливаемого stderr, байт */
    private const STDERR_LIMIT = 1_000_000;

    /**
     * @param  string  $hostKey  открытый ключ хоста в формате "<тип> <base64>"
     * @param  string|null  $privateKey  закрытый ключ без парольной фразы (OpenSSH), либо null при парольной авторизации
     * @param  callable(string): void  $onChunk
     * @return array{exit_code: int|null, stderr: string}
     *
     * @throws SshConnectionException
     */
    public function run(
        string $host,
        int $port,
        string $username,
        string $hostKey,
        ?string $privateKey,
        ?string $password,
        string $command,
        callable $onChunk,
        int $timeout,
        int $connectTimeout,
    ): array {
        $dir = $this->makeTempDir();
        $process = null;
        $pipes = [];

        try {
            $args = [(string) config('ssh.openssh_binary'), '-T', '-n', '-p', (string) $port, '-l', $username];
            $env = null;

            file_put_contents("{$dir}/known_hosts", 'sk-host '.$this->hostKeyLine($hostKey)."\n");
            foreach ([
                'UserKnownHostsFile' => "{$dir}/known_hosts",
                'GlobalKnownHostsFile' => '/dev/null',
                'StrictHostKeyChecking' => 'yes',
                'HostKeyAlias' => 'sk-host',
                'CheckHostIP' => 'no',
                'HostKeyAlgorithms' => $this->hostKeyAlgorithms($hostKey),
                'ConnectTimeout' => (string) $connectTimeout,
                'ServerAliveInterval' => '30',
                'ServerAliveCountMax' => '4',
                'LogLevel' => 'ERROR',
                'Compression' => 'no',
                'NumberOfPasswordPrompts' => '1',
            ] as $name => $value) {
                array_push($args, '-o', "{$name}={$value}");
            }

            if ($privateKey !== null) {
                $keyFile = "{$dir}/id";
                $this->writePrivate($keyFile, $privateKey);
                array_push($args, '-i', $keyFile, '-o', 'IdentitiesOnly=yes', '-o', 'PreferredAuthentications=publickey', '-o', 'BatchMode=yes');
            } else {
                $askpass = "{$dir}/askpass";
                $this->writePrivate($askpass, "#!/bin/sh\nprintf '%s\\n' \"\$SK_SSH_PASSWORD\"\n", 0700);
                array_push($args, '-o', 'PreferredAuthentications=password,keyboard-interactive', '-o', 'PubkeyAuthentication=no');
                $env = array_merge(getenv() ?: [], [
                    'SSH_ASKPASS' => $askpass,
                    'SSH_ASKPASS_REQUIRE' => 'force',
                    'DISPLAY' => getenv('DISPLAY') ?: ':0',
                    'SK_SSH_PASSWORD' => (string) $password,
                ]);
            }

            array_push($args, $host, $command);

            try {
                $process = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            } catch (Throwable $e) {
                $process = false;
            }
            if (! is_resource($process)) {
                throw new SshConnectionException("Не удалось запустить системный ssh «{$args[0]}» (проверьте SSH_BINARY и наличие openssh-client).");
            }

            return $this->pump($process, $pipes, $onChunk, $timeout);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 15);
                    usleep(200_000);
                    if (proc_get_status($process)['running']) {
                        proc_terminate($process, 9);
                    }
                }
                proc_close($process);
            }
            $this->removeDir($dir);
        }
    }

    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     * @param  callable(string): void  $onChunk
     * @return array{exit_code: int|null, stderr: string}
     */
    private function pump($process, array $pipes, callable $onChunk, int $timeout): array
    {
        [, $out, $err] = $pipes;
        // По умолчанию PHP читает из канала по 8 КБ — на больших потоках это десятки тысяч лишних вызовов
        stream_set_chunk_size($out, self::CHUNK);
        stream_set_blocking($out, false);
        stream_set_blocking($err, false);

        $stderr = '';
        $exitCode = null;
        $deadline = microtime(true) + $timeout;
        $open = [$out, $err];

        while ($open !== []) {
            if (microtime(true) > $deadline) {
                throw new SshConnectionException('Команда не завершилась за отведённое время.');
            }

            $read = $open;
            $write = $except = null;
            if (stream_select($read, $write, $except, 1) === false) {
                break;
            }

            foreach ($read as $stream) {
                $data = fread($stream, self::CHUNK);
                if ($data === '' || $data === false) {
                    if (feof($stream)) {
                        $open = array_values(array_filter($open, fn ($s) => $s !== $stream));
                    }

                    continue;
                }

                if ($stream === $out) {
                    $onChunk($data);
                } elseif (strlen($stderr) < self::STDERR_LIMIT) {
                    $stderr .= $data;
                }
            }
        }

        // Код выхода надо взять при первом "не running": после этого proc_close вернёт -1
        for ($i = 0; $i < 600; $i++) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = $status['exitcode'] >= 0 ? $status['exitcode'] : null;
                break;
            }
            usleep(50_000);
        }
        if ($exitCode === null && proc_get_status($process)['running']) {
            throw new SshConnectionException('Процесс ssh не завершился после закрытия потока.');
        }

        return ['exit_code' => $exitCode, 'stderr' => $stderr];
    }

    private function makeTempDir(): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/').'/sk-ssh-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new SshConnectionException('Не удалось создать временный каталог для ssh.');
        }

        return $dir;
    }

    /** Файл создаётся сразу с нужными правами — содержимое не бывает доступно другим даже на мгновение. */
    private function writePrivate(string $path, string $content, int $mode = 0600): void
    {
        $old = umask(0077);
        try {
            $handle = fopen($path, 'xb');
            if ($handle === false) {
                throw new SshConnectionException('Не удалось создать временный файл для ssh.');
            }
            chmod($path, $mode);
            fwrite($handle, rtrim($content, "\n")."\n");
            fclose($handle);
        } finally {
            umask($old);
        }
    }

    private function removeDir(string $dir): void
    {
        try {
            foreach (glob("{$dir}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        } catch (Throwable) {
            // уборка не должна маскировать основную ошибку
        }
    }

    /** "<тип> <base64>" без комментария. */
    private function hostKeyLine(string $hostKey): string
    {
        $parts = preg_split('/\s+/', trim($hostKey));

        return ($parts[0] ?? '').' '.($parts[1] ?? '');
    }

    /** Алгоритмы, которыми хост может подписаться этим ключом: для RSA сервер использует rsa-sha2-*. */
    private function hostKeyAlgorithms(string $hostKey): string
    {
        $type = preg_split('/\s+/', trim($hostKey))[0] ?? '';

        return $type === 'ssh-rsa' ? 'rsa-sha2-512,rsa-sha2-256,ssh-rsa' : $type;
    }
}
