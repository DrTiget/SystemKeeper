<?php

namespace Tests\Feature;

use App\Exceptions\Ssh\HostFingerprintChangedException;
use App\Exceptions\Ssh\SshConnectionException;
use App\Exceptions\Storage\StorageException;
use App\Models\Server;
use App\Models\StorageTarget;
use App\Services\Ssh\SshConnectorInterface;
use App\Services\Storage\Drivers\SftpDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Net\SSH2;
use Tests\TestCase;

class SftpDriverTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    /** @var list<array{command: string, stdin: string|null, timeout: int|null}> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['storage_targets.sftp.backoff_ms' => 0, 'storage_targets.sftp.attempts' => 3]);
        $this->file = tempnam(sys_get_temp_dir(), 'sk-sftp-');
        file_put_contents($this->file, 'backup-content');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    /** @param  callable(string $command, int $call): array{0: int|null, 1?: string, 2?: string}|\Throwable  $handler */
    private function driver(callable $handler, string $base = '/srv/backups'): SftpDriver
    {
        $test = $this;
        $ssh = new class($handler, $test) implements SshConnectorInterface
        {
            public function __construct(private $handler, private $test) {}

            public function connect(Server $server): SSH2
            {
                throw new \LogicException;
            }

            public function exec(Server $server, string $command, ?int $timeout = null): array
            {
                throw new \LogicException;
            }

            public function stream(Server $server, string $command, callable $onChunk, ?int $timeout = null, ?string $stdinFile = null): array
            {
                $call = count($this->test->logRef());
                $this->test->logRef()[] = ['command' => $command, 'stdin' => $stdinFile, 'timeout' => $timeout];
                $r = ($this->handler)($command, $call);
                if ($r instanceof \Throwable) {
                    throw $r;
                }
                if (($r[1] ?? '') !== '') {
                    $onChunk($r[1]);
                }

                return ['exit_code' => $r[0], 'stderr' => $r[2] ?? ''];
            }
        };

        $target = StorageTarget::factory()->sftp(null, $base)->create();

        return new SftpDriver($target, $ssh);
    }

    public function &logRef(): array
    {
        return $this->log;
    }

    private function sha(): string
    {
        return hash('sha256', 'backup-content');
    }

    public function test_upload_streams_file_verifies_sha256_and_renames(): void
    {
        $driver = $this->driver(fn ($cmd) => str_contains($cmd, 'sha256sum') ? [0, $this->sha()."\n"] : [0]);

        $result = $driver->upload($this->file, '/srv/backups/srv 1/o\'brien.tar.zst');

        $this->assertSame(['md5' => null, 'sha256' => $this->sha()], $result);
        $this->assertCount(3, $this->log);
        $this->assertSame($this->file, $this->log[0]['stdin']);
        $this->assertSame("mkdir -p '/srv/backups/srv 1' && umask 077 && cat > '/srv/backups/srv 1/o'\\''brien.tar.zst.part'", $this->log[0]['command']);
        $this->assertNull($this->log[1]['stdin']);
        $this->assertStringContainsString("sha256sum '/srv/backups/srv 1/o'\\''brien.tar.zst.part'", $this->log[1]['command']);
        $this->assertStringStartsWith("mv -f '/srv/backups/srv 1/o'\\''brien.tar.zst.part' '/srv/backups/srv 1/o'\\''brien.tar.zst'", $this->log[2]['command']);
    }

    public function test_checksum_mismatch_removes_partial_file_and_fails(): void
    {
        $driver = $this->driver(fn ($cmd) => str_contains($cmd, 'sha256sum') ? [0, str_repeat('a', 64)] : [0]);

        try {
            $driver->upload($this->file, '/srv/backups/a.bin');
            $this->fail('Ожидалось исключение');
        } catch (StorageException $e) {
            $this->assertStringContainsString('Контрольная сумма не совпала', $e->getMessage());
        }
        $this->assertStringStartsWith("rm -f '/srv/backups/a.bin.part'", end($this->log)['command']);
        $this->assertFalse(collect($this->log)->contains(fn ($c) => str_starts_with($c['command'], 'mv ')));
    }

    public function test_remote_write_error_is_reported_with_stderr(): void
    {
        $driver = $this->driver(fn () => [1, '', "cat: write error: No space left on device\n"]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('No space left on device');
        $driver->upload($this->file, '/srv/backups/a.bin');
    }

    public function test_retries_connection_failures_but_not_command_failures(): void
    {
        $attempt = 0;
        $driver = $this->driver(function ($cmd) use (&$attempt) {
            if (str_contains($cmd, 'cat >') && ++$attempt < 3) {
                return new SshConnectionException('Ошибка SSH: connection reset');
            }

            return str_contains($cmd, 'sha256sum') ? [0, $this->sha()] : [0];
        });

        $driver->upload($this->file, '/srv/backups/a.bin');
        $this->assertSame(3, $attempt);

        $this->log = [];
        $failing = $this->driver(fn () => [1, '', 'Permission denied']);
        try {
            $failing->upload($this->file, '/srv/backups/a.bin');
        } catch (StorageException) {
        }
        $this->assertCount(1, $this->log);
    }

    public function test_changed_host_fingerprint_is_not_retried(): void
    {
        $driver = $this->driver(fn () => new HostFingerprintChangedException);

        try {
            $driver->upload($this->file, '/srv/backups/a.bin');
            $this->fail('Ожидалось исключение');
        } catch (StorageException $e) {
            $this->assertStringContainsString('Отпечаток хоста изменился', $e->getMessage());
        }
        $this->assertCount(1, $this->log);
    }

    public function test_paths_outside_base_are_rejected(): void
    {
        $driver = $this->driver(fn () => [0]);

        foreach (['/etc/passwd', '/srv/backups/../etc/passwd', '/srv/backups-evil/a'] as $bad) {
            foreach (['upload' => fn () => $driver->upload($this->file, $bad), 'delete' => fn () => $driver->delete($bad)] as $op) {
                try {
                    $op();
                    $this->fail("Ожидалось исключение для {$bad}");
                } catch (StorageException $e) {
                    $this->assertStringContainsString('вне базовой папки', $e->getMessage());
                }
            }
        }
        $this->assertSame([], $this->log);
    }

    public function test_delete_runs_rm(): void
    {
        $this->driver(fn () => [0])->delete('/srv/backups/x y/a.bin');
        // driver() создаёт новую цель — повторим с логом
        $driver = $this->driver(fn () => [0]);
        $this->log = [];
        $driver->delete('/srv/backups/x y/a.bin');

        $this->assertSame("rm -f '/srv/backups/x y/a.bin'", $this->log[0]['command']);
    }

    public function test_check_parses_df_and_probes_write(): void
    {
        $driver = $this->driver(fn () => [0, "/dev/sda1 1000 400 600 40% /srv\n"]);

        $this->assertSame(['total_space' => 1000 * 1024, 'used_space' => 400 * 1024, 'free_space' => 600 * 1024], $driver->check());
        $this->assertStringContainsString("mkdir -p '/srv/backups'", $this->log[0]['command']);
        $this->assertStringContainsString('df -Pk', $this->log[0]['command']);
    }

    public function test_check_fails_when_not_writable(): void
    {
        $driver = $this->driver(fn () => [1, '', "sh: 1: cannot create /srv/backups/.x: Permission denied\n"]);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Permission denied');
        $driver->check();
    }
}
