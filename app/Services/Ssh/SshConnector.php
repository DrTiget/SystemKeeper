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
    public function exec(Server $server, string $command, ?int $timeout = null, ?string $stdin = null): array
    {
        $ssh = $this->connect($server);
        $ssh->setTimeout($timeout ?? (int) config('ssh.exec_timeout'));
        $ssh->enableQuietMode();

        try {
            $stdout = $stdin === null ? (string) $ssh->exec($command) : $this->execWithStdin($ssh, $command, $stdin);
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

    public function stream(Server $server, string $command, callable $onOutput, ?int $timeout = null, ?string $stdin = null): array
    {
        $ssh = $this->connect($server);
        $ssh->setTimeout($timeout ?? (int) config('ssh.exec_timeout'));
        $ssh->enableQuietMode();

        try {
            $ssh->exec($command, false);
            if ($stdin !== null) {
                $ssh->write($stdin, SSH2::CHANNEL_EXEC);
            }
            $ssh->sendEOF(SSH2::CHANNEL_EXEC);
            while (true) {
                $chunk = $ssh->read('', SSH2::READ_NEXT, SSH2::CHANNEL_EXEC);
                if ($chunk === true || $chunk === false || $ssh->isTimeout()) {
                    break;
                }
                if ($chunk !== '') {
                    $onOutput($chunk);
                }
            }
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
            'stderr' => (string) $stderr,
        ];
    }

    /** Запускает команду без ожидания, пишет в её stdin и читает stdout до закрытия канала. */
    private function execWithStdin(SSH2 $ssh, string $command, string $stdin): string
    {
        $ssh->exec($command, false);
        $ssh->write($stdin, SSH2::CHANNEL_EXEC);
        // Конец ввода: команды, читающие stdin целиком (mysql < sql), иначе ждут вечно
        $ssh->sendEOF(SSH2::CHANNEL_EXEC);

        $stdout = '';
        while (true) {
            $chunk = $ssh->read('', SSH2::READ_NEXT, SSH2::CHANNEL_EXEC);
            if ($chunk === true || $chunk === false || $ssh->isTimeout()) {
                break;
            }
            $stdout .= $chunk;
        }

        return $stdout;
    }

    private function connect(Server $server): SSH2
    {
        $ssh = new SSH2($server->host, $server->port, (int) config('ssh.connect_timeout'));

        try {
            $hostKey = $ssh->getServerPublicHostKey();
        } catch (Throwable $e) {
            throw new SshConnectionException("Не удалось подключиться к {$server->host}:{$server->port}: {$e->getMessage()}", previous: $e);
        }
        if ($hostKey === false) {
            throw new SshConnectionException("Не удалось подключиться к {$server->host}:{$server->port}.");
        }

        $fingerprint = self::fingerprintOf($hostKey);
        if ($server->host_fingerprint !== null && ! hash_equals($server->host_fingerprint, $fingerprint)) {
            throw new HostFingerprintChangedException;
        }

        try {
            $credential = $server->auth_type === Server::AUTH_KEY
                ? PublicKeyLoader::load((string) $server->private_key, $server->passphrase ?: false)
                : (string) $server->password;
            $ok = $ssh->login($server->username, $credential);
        } catch (Throwable $e) {
            throw new SshConnectionException("Ошибка авторизации: {$e->getMessage()}", previous: $e);
        }

        if (! $ok) {
            throw new SshConnectionException("Авторизация не удалась для пользователя {$server->username}.");
        }

        // Отпечаток запоминаем только после успешной авторизации
        if ($server->host_fingerprint === null) {
            $server->forceFill(['host_fingerprint' => $fingerprint]);
            // Несохранённую модель (разовое подключение) в БД не записываем
            if ($server->exists) {
                $server->save();
            }
        }

        return $ssh;
    }

    /** SHA256-отпечаток ключа хоста в формате OpenSSH. */
    private static function fingerprintOf(string $hostKey): string
    {
        // host key в формате authorized_keys: "<type> <base64>"
        $blob = base64_decode(explode(' ', trim($hostKey))[1] ?? '', true) ?: $hostKey;

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }
}
