<?php

namespace App\Services\Ssh;

use App\Exceptions\Ssh\HostFingerprintChangedException;
use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Server;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;

class SshConnector implements SshConnectorInterface
{
    public function connect(Server $server): SSH2
    {
        $ssh = new SSH2($server->host, $server->port, (int) config('ssh.connect_timeout'));

        try {
            $hostKey = $ssh->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new SshConnectionException("Не удалось подключиться к {$server->host}:{$server->port}: {$e->getMessage()}", previous: $e);
        }

        $this->verifyHostFingerprint($server, $this->fingerprintOf($hostKey));

        try {
            $credential = $server->auth_type === Server::AUTH_KEY
                ? PublicKeyLoader::load($server->sshKey->private_key, $server->sshKey->passphrase ?: false)
                : $server->password;
            $ok = $ssh->login($server->username, $credential);
        } catch (Throwable $e) {
            throw new SshConnectionException("Ошибка авторизации: {$e->getMessage()}", previous: $e);
        }

        if (! $ok) {
            throw new SshConnectionException("Авторизация не удалась для пользователя {$server->username}.");
        }

        $this->rememberHostFingerprint($server, $this->fingerprintOf($hostKey));

        return $ssh;
    }

    public function exec(Server $server, string $command, ?int $timeout = null): array
    {
        $ssh = $this->connect($server);
        $ssh->setTimeout($timeout ?? (int) config('ssh.exec_timeout'));
        $ssh->enableQuietMode();

        try {
            $stdout = (string) $ssh->exec($command);
        } catch (Throwable $e) {
            throw new SshConnectionException("Ошибка выполнения команды: {$e->getMessage()}", previous: $e);
        } finally {
            $stderr = $ssh->getStdError();
            $ssh->disconnect();
        }

        if ($ssh->isTimeout()) {
            throw new SshConnectionException('Команда не завершилась за отведённое время.');
        }

        return [
            'exit_code' => $ssh->getExitStatus() === false ? null : $ssh->getExitStatus(),
            'stdout' => $stdout,
            'stderr' => (string) $stderr,
        ];
    }

    public function stream(Server $server, string $command, callable $onChunk, ?int $timeout = null, ?string $stdinFile = null): array
    {
        if ($stdinFile !== null || config('ssh.stream_driver') === 'openssh') {
            return $this->streamViaOpenSsh($server, $command, $onChunk, $timeout, $stdinFile);
        }

        return $this->streamViaPhpseclib($server, $command, $onChunk, $timeout);
    }

    /**
     * Стрим через системный ssh. TOFU сохраняется: отпечаток хоста сверяется с сохранённым до запуска
     * клиента, а сам ssh получает known_hosts с единственным — уже проверенным — ключом и StrictHostKeyChecking=yes.
     * При первом подключении отпечаток запоминается только после успешной авторизации (как и в connect()).
     */
    private function streamViaOpenSsh(Server $server, string $command, callable $onChunk, ?int $timeout, ?string $stdinFile = null): array
    {
        $hostKey = $this->fetchHostKey($server);
        $fingerprint = $this->fingerprintOf($hostKey);
        $this->verifyHostFingerprint($server, $fingerprint);

        try {
            $privateKey = $server->auth_type === Server::AUTH_KEY
                // withPassword() снимает парольную фразу: ssh не умеет спрашивать её неинтерактивно (иначе toString() зашифровал бы ключ снова)
                ? PublicKeyLoader::load($server->sshKey->private_key, $server->sshKey->passphrase ?: false)->withPassword()->toString('OpenSSH')
                : null;
        } catch (Throwable $e) {
            throw new SshConnectionException("Ошибка авторизации: {$e->getMessage()}", previous: $e);
        }

        $result = app(OpenSshStreamRunner::class)->run(
            host: $server->host,
            port: $server->port,
            username: $server->username,
            hostKey: $hostKey,
            privateKey: $privateKey,
            password: $privateKey === null ? $server->password : null,
            command: $command,
            onChunk: $onChunk,
            timeout: $timeout ?? (int) config('backup.timeout'),
            connectTimeout: (int) config('ssh.connect_timeout'),
            stdinFile: $stdinFile,
        );

        // 255 — ошибка самого ssh (соединение, авторизация, ключ хоста), а не код удалённой команды
        if ($result['exit_code'] === 255) {
            throw new SshConnectionException('Ошибка SSH: '.(trim($result['stderr']) ?: 'соединение не удалось.'));
        }

        $this->rememberHostFingerprint($server, $fingerprint);

        return $result;
    }

    private function streamViaPhpseclib(Server $server, string $command, callable $onChunk, ?int $timeout): array
    {
        $ssh = $this->connect($server);
        $ssh->setTimeout($timeout ?? (int) config('backup.timeout'));
        // Quiet mode разделяет stderr и stdout, иначе диагностика попала бы в поток данных
        $ssh->enableQuietMode();

        $callbackError = null;

        try {
            // Колбэк не должен возвращать true — это прервало бы команду
            $ssh->exec($command, function (string $chunk) use ($onChunk, &$callbackError): void {
                try {
                    $onChunk($chunk);
                } catch (Throwable $e) {
                    $callbackError = $e;
                    throw $e;
                }
            });
            $timedOut = $ssh->isTimeout();
            $exitCode = $ssh->getExitStatus();
        } catch (Throwable $e) {
            // Ошибки обработчика данных (например, диск) отдаём как есть, остальное — ошибка SSH
            throw $callbackError ?? new SshConnectionException("Ошибка выполнения команды: {$e->getMessage()}", previous: $e);
        } finally {
            $stderr = (string) $ssh->getStdError();
            $ssh->disconnect();
        }

        if ($timedOut) {
            throw new SshConnectionException('Команда не завершилась за отведённое время.');
        }

        return ['exit_code' => $exitCode === false ? null : $exitCode, 'stderr' => $stderr];
    }

    /**
     * Открытый ключ хоста ("<тип> <base64>") без авторизации.
     *
     * @throws SshConnectionException
     */
    protected function fetchHostKey(Server $server): string
    {
        $ssh = new SSH2($server->host, $server->port, (int) config('ssh.connect_timeout'));

        try {
            return $ssh->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new SshConnectionException("Не удалось подключиться к {$server->host}:{$server->port}: {$e->getMessage()}", previous: $e);
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * @throws HostFingerprintChangedException если отпечаток известен и не совпал
     */
    public function verifyHostFingerprint(Server $server, string $fingerprint): void
    {
        if ($server->host_fingerprint !== null && ! hash_equals($server->host_fingerprint, $fingerprint)) {
            throw new HostFingerprintChangedException;
        }
    }

    public function rememberHostFingerprint(Server $server, string $fingerprint): void
    {
        if ($server->host_fingerprint === null) {
            $server->forceFill(['host_fingerprint' => $fingerprint])->save();
        }
    }

    private function fingerprintOf(string $hostKey): string
    {
        // host key в формате authorized_keys: "<type> <base64>"
        $blob = base64_decode(explode(' ', trim($hostKey))[1] ?? '', true) ?: $hostKey;

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
