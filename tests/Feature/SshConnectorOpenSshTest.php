<?php

namespace Tests\Feature;

use App\Exceptions\Ssh\HostFingerprintChangedException;
use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Server;
use App\Models\SshKey;
use App\Services\Ssh\SshConnector;
use App\Services\Ssh\SshKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\PublicKeyLoader;
use Tests\Support\FakeSshBinary;
use Tests\TestCase;

class SshConnectorOpenSshTest extends TestCase
{
    use RefreshDatabase;

    private const BLOB = 'fake-host-key-blob';

    private ?FakeSshBinary $binary = null;

    private function hostKey(): string
    {
        return 'ssh-ed25519 '.base64_encode(self::BLOB).' root@host';
    }

    private function fingerprint(): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', self::BLOB, true)), '=');
    }

    /** Коннектор без реального сетевого рукопожатия: ключ хоста подставляется. */
    private function connector(?string $hostKey = null): SshConnector
    {
        $key = $hostKey ?? $this->hostKey();

        return new class($key) extends SshConnector
        {
            public function __construct(private string $key) {}

            protected function fetchHostKey(Server $server): string
            {
                return $this->key;
            }
        };
    }

    private function useBinary(string $mode = 'ok'): FakeSshBinary
    {
        $ssh = $this->binary = new FakeSshBinary($mode);
        config(['ssh.stream_driver' => 'openssh', 'ssh.openssh_binary' => $ssh->path]);

        return $ssh;
    }

    public function test_openssh_is_the_default_driver(): void
    {
        $this->assertSame('openssh', config('ssh.stream_driver'));
    }

    public function test_first_connection_pins_host_key_and_remembers_fingerprint_after_success(): void
    {
        $ssh = $this->useBinary();
        $server = Server::factory()->create();
        $out = '';

        $result = $this->connector()->stream($server, 'tar -cf - /etc', function ($c) use (&$out) {
            $out .= $c;
        });

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame('chunk1-chunk2', $out);
        $this->assertSame($this->fingerprint(), $server->refresh()->host_fingerprint);
        $this->assertSame('sk-host ssh-ed25519 '.base64_encode(self::BLOB)."\n", $ssh->read('known_hosts'));
        $this->assertContains($server->username, $ssh->log());
        $this->assertContains((string) $server->port, $ssh->log());
        $this->assertSame('tar -cf - /etc', last($ssh->log()));
    }

    public function test_changed_host_fingerprint_is_rejected_before_ssh_is_started(): void
    {
        $ssh = $this->useBinary();
        $server = Server::factory()->create(['host_fingerprint' => 'SHA256:old-fingerprint']);

        try {
            $this->connector()->stream($server, 'true', fn () => null);
            $this->fail('Ожидалось HostFingerprintChangedException.');
        } catch (HostFingerprintChangedException) {
            $this->assertSame([], $ssh->log(), 'ssh не должен запускаться');
            $this->assertSame('SHA256:old-fingerprint', $server->refresh()->host_fingerprint);
        }
    }

    public function test_matching_known_fingerprint_is_accepted(): void
    {
        $this->useBinary();
        $server = Server::factory()->create(['host_fingerprint' => $this->fingerprint()]);

        $this->assertSame(0, $this->connector()->stream($server, 'true', fn () => null)['exit_code']);
    }

    public function test_ssh_level_failure_255_raises_and_does_not_pin_fingerprint(): void
    {
        $this->useBinary('fail255');
        $server = Server::factory()->create();

        try {
            $this->connector()->stream($server, 'true', fn () => null);
            $this->fail('Ожидалось SshConnectionException.');
        } catch (SshConnectionException $e) {
            $this->assertStringContainsString('Permission denied', $e->getMessage());
            $this->assertNull($server->refresh()->host_fingerprint);
        }
    }

    public function test_remote_command_failure_is_returned_and_login_counts_as_successful(): void
    {
        $this->useBinary('exit3');
        $server = Server::factory()->create();

        $result = $this->connector()->stream($server, 'false', fn () => null);

        $this->assertSame(3, $result['exit_code']);
        $this->assertSame($this->fingerprint(), $server->refresh()->host_fingerprint);
    }

    public function test_passphrase_protected_key_is_passed_to_ssh_decrypted(): void
    {
        $ssh = $this->useBinary();
        $material = app(SshKeyService::class)->generate('ed25519', 'k');
        $encrypted = PublicKeyLoader::load($material->privateKey)->withPassword('s3cret')->toString('OpenSSH');
        $key = SshKey::factory()->create([
            'private_key' => $encrypted, 'passphrase' => 's3cret', 'public_key' => $material->publicKey, 'fingerprint' => $material->fingerprint,
        ]);
        $server = Server::factory()->create(['ssh_key_id' => $key->id]);

        $this->connector()->stream($server, 'true', fn () => null);

        $passed = $ssh->read('key');
        $this->assertNotSame($encrypted, $passed);
        // файл читается без парольной фразы и это тот же ключ
        $loaded = PublicKeyLoader::load($passed, false);
        $this->assertSame($material->fingerprint, 'SHA256:'.$loaded->getPublicKey()->getFingerprint('sha256'));
        $this->assertSame('600', trim($ssh->read('key_mode')));
    }

    public function test_password_server_uses_askpass_not_key(): void
    {
        $ssh = $this->useBinary();
        $server = Server::factory()->withPassword('pw-123')->create();

        $this->connector()->stream($server, 'true', fn () => null);

        $this->assertSame("pw-123\n", $ssh->read('askpass_out'));
        $this->assertNull($ssh->read('key'));
    }

    public function test_default_timeout_comes_from_backup_config(): void
    {
        $this->useBinary('sleep');
        config(['backup.timeout' => 1]);
        $server = Server::factory()->create();

        $this->expectException(SshConnectionException::class);
        $this->expectExceptionMessage('отведённое время');

        $this->connector()->stream($server, 'sleep 30', fn () => null);
    }
}
