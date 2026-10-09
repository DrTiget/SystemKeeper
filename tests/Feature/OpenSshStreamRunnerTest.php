<?php

namespace Tests\Feature;

use App\Exceptions\Ssh\SshConnectionException;
use App\Services\Ssh\OpenSshStreamRunner;
use RuntimeException;
use Tests\Support\FakeSshBinary;
use Tests\TestCase;

class OpenSshStreamRunnerTest extends TestCase
{
    private const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeHostKeyForTestsOnly000000000000000000 root@host';

    private function exec(FakeSshBinary $ssh, ?string $key = "-----BEGIN KEY-----\nabc\n", ?string $password = null, string $command = 'tar -cf - /etc', ?callable $onChunk = null, int $timeout = 10, string $hostKey = self::HOST_KEY, string &$collected = ''): array
    {
        config(['ssh.openssh_binary' => $ssh->path]);

        return (new OpenSshStreamRunner)->run(
            host: 'srv.example.com', port: 2222, username: 'backup', hostKey: $hostKey,
            privateKey: $key, password: $password, command: $command,
            onChunk: $onChunk ?? function (string $c) use (&$collected) {
                $collected .= $c;
            },
            timeout: $timeout, connectTimeout: 7,
        );
    }

    public function test_streams_stdout_and_returns_stderr_and_exit_code(): void
    {
        $ssh = new FakeSshBinary('ok');
        $out = '';

        $result = $this->exec($ssh, collected: $out);

        $this->assertSame('chunk1-chunk2', $out);
        $this->assertSame(['exit_code' => 0, 'stderr' => "warn\n"], $result);
    }

    public function test_nonzero_exit_code_is_returned(): void
    {
        $result = $this->exec(new FakeSshBinary('exit3'));

        $this->assertSame(3, $result['exit_code']);
        $this->assertSame("remote failed\n", $result['stderr']);
    }

    public function test_builds_strict_command_line(): void
    {
        $ssh = new FakeSshBinary;
        $this->exec($ssh);
        $args = $ssh->log();
        $joined = implode(' ', $args);

        $this->assertSame('tar -cf - /etc', end($args), 'удалённая команда — последний аргумент одним куском');
        $this->assertSame('srv.example.com', $args[count($args) - 2]);
        foreach (['-T', '-n', '-p', '2222', '-l', 'backup', 'StrictHostKeyChecking=yes', 'GlobalKnownHostsFile=/dev/null', 'HostKeyAlias=sk-host',
            'HostKeyAlgorithms=ssh-ed25519', 'ConnectTimeout=7', 'BatchMode=yes', 'IdentitiesOnly=yes', 'PreferredAuthentications=publickey', 'CheckHostIP=no'] as $needle) {
            $this->assertStringContainsString($needle, $joined);
        }
        $this->assertStringNotContainsString('StrictHostKeyChecking=no', $joined);
    }

    public function test_known_hosts_pins_exactly_the_given_key_without_comment(): void
    {
        $ssh = new FakeSshBinary;
        $this->exec($ssh);

        $this->assertSame('sk-host ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeHostKeyForTestsOnly000000000000000000'."\n", $ssh->read('known_hosts'));
    }

    public function test_rsa_host_key_allows_sha2_signatures(): void
    {
        $ssh = new FakeSshBinary;
        $this->exec($ssh, hostKey: 'ssh-rsa AAAAB3NzaC1yc2E');

        $this->assertContains('HostKeyAlgorithms=rsa-sha2-512,rsa-sha2-256,ssh-rsa', $ssh->log());
    }

    public function test_private_key_is_0600_during_run_and_everything_is_removed_after(): void
    {
        $ssh = new FakeSshBinary;
        $this->exec($ssh, key: "-----BEGIN KEY-----\nsecret-key-body");

        $this->assertSame("-----BEGIN KEY-----\nsecret-key-body\n", $ssh->read('key'));
        $this->assertSame('600', trim($ssh->read('key_mode')));
        $this->assertFileDoesNotExist(trim($ssh->read('key_path')));
        $this->assertDirectoryDoesNotExist(dirname(trim($ssh->read('key_path'))));
        $this->assertFileDoesNotExist(trim($ssh->read('kh_path')));
    }

    public function test_password_auth_uses_askpass_and_keeps_password_off_command_line(): void
    {
        $ssh = new FakeSshBinary;
        $this->exec($ssh, key: null, password: 'p@ss w0rd');

        $this->assertSame('p@ss w0rd', $ssh->read('password_env'));
        $this->assertSame("p@ss w0rd\n", $ssh->read('askpass_out'));
        $this->assertSame('700', trim($ssh->read('askpass_mode')));
        $this->assertStringNotContainsString('p@ss', implode(' ', $ssh->log()));
        $this->assertContains('PubkeyAuthentication=no', $ssh->log());
        $this->assertNull($ssh->read('key'));
    }

    public function test_streams_large_output_without_loss(): void
    {
        $ssh = new FakeSshBinary('big');
        $total = 0;
        $hash = hash_init('sha256');

        $this->exec($ssh, onChunk: function (string $c) use (&$total, $hash) {
            $total += strlen($c);
            hash_update($hash, $c);
        });

        $this->assertSame(5_000_000, $total);
        $this->assertSame(hash('sha256', str_repeat("\0", 5_000_000)), hash_final($hash));
    }

    public function test_timeout_kills_process_and_cleans_up(): void
    {
        $ssh = new FakeSshBinary('sleep');
        $started = microtime(true);

        try {
            $this->exec($ssh, timeout: 1);
            $this->fail('Ожидался таймаут.');
        } catch (SshConnectionException $e) {
            $this->assertStringContainsString('отведённое время', $e->getMessage());
        }

        $this->assertLessThan(8, microtime(true) - $started);
        $this->assertDirectoryDoesNotExist(dirname(trim($ssh->read('key_path'))));
        $this->assertDirectoryDoesNotExist('/proc/'.trim($ssh->read('pid')), 'процесс ssh должен быть убит');
    }

    public function test_exception_from_chunk_handler_aborts_and_propagates(): void
    {
        $ssh = new FakeSshBinary('sleep');

        try {
            $this->exec($ssh, onChunk: fn () => throw new RuntimeException('диск переполнен'));
            $this->fail('Ожидалось исключение обработчика.');
        } catch (RuntimeException $e) {
            $this->assertSame('диск переполнен', $e->getMessage());
        }

        $this->assertDirectoryDoesNotExist(dirname(trim($ssh->read('key_path'))));
        $this->assertDirectoryDoesNotExist('/proc/'.trim($ssh->read('pid')));
    }

    public function test_missing_binary_is_reported_as_connection_error(): void
    {
        config(['ssh.openssh_binary' => '/nonexistent/ssh']);

        $this->expectException(SshConnectionException::class);
        $this->expectExceptionMessage('Не удалось запустить системный ssh');

        (new OpenSshStreamRunner)->run('h', 22, 'u', self::HOST_KEY, 'k', null, 'true', fn () => null, 5, 5);
    }
}
