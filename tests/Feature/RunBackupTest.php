<?php

namespace Tests\Feature;

use App\Exceptions\Ssh\SshConnectionException;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\Server;
use App\Services\Backup\LocalCommandRunner;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeLocalCommandRunner;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class RunBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
    }

    private function fakeSsh(array $chunks = ['ab', 'cd', 'ef'], array $result = ['exit_code' => 0, 'stderr' => '']): FakeSshConnector
    {
        $fake = new FakeSshConnector;
        $fake->streamChunks = $chunks;
        $fake->streamResult = $result;
        $this->app->instance(SshConnectorInterface::class, $fake);

        return $fake;
    }

    private function pending(BackupJob $job): Backup
    {
        return $job->backups()->create(['status' => Backup::STATUS_PENDING]);
    }

    public function test_streams_files_backup_into_local_file_with_checksums(): void
    {
        $fake = $this->fakeSsh();
        $job = BackupJob::factory()->create();
        $backup = $this->pending($job);

        RunBackup::dispatchSync($backup);

        $backup->refresh();
        $this->assertSame(Backup::STATUS_SUCCESS, $backup->status);
        $this->assertStringStartsWith("{$job->server_id}/{$job->id}/", $backup->file_path);
        $this->assertStringEndsWith("-{$backup->id}.tar.zst", $backup->file_path);
        Storage::disk('backups')->assertExists($backup->file_path);
        $this->assertSame('abcdef', Storage::disk('backups')->get($backup->file_path));
        $this->assertSame(6, $backup->size_bytes);
        $this->assertSame(hash('sha256', 'abcdef'), $backup->sha256);
        $this->assertSame(md5('abcdef'), $backup->md5);
        $this->assertNotNull($backup->started_at);
        $this->assertNotNull($backup->finished_at);
        $this->assertNull($backup->error);

        $this->assertCount(1, $fake->calls);
        $this->assertSame($job->server_id, $fake->calls[0]['server']);
        $this->assertStringContainsString('tar -C /', $fake->calls[0]['command']);
        $this->assertStringContainsString('zstd', $fake->calls[0]['command']);
    }

    public function test_mysql_over_ssh_passes_password_via_env_prefix_and_stores_log(): void
    {
        $fake = $this->fakeSsh(['dump'], ['exit_code' => 0, 'stderr' => 'warning: something']);
        $backup = $this->pending(BackupJob::factory()->mysql()->create());

        RunBackup::dispatchSync($backup);

        $backup->refresh();
        $this->assertSame(Backup::STATUS_SUCCESS, $backup->status);
        $this->assertStringEndsWith('.sql.zst', $backup->file_path);
        $this->assertSame('warning: something', $backup->log);
        $this->assertStringStartsWith("env MYSQL_PWD='db-secret-pass' bash -o pipefail", $fake->calls[0]['command']);
        $this->assertStringContainsString('mysqldump', $fake->calls[0]['command']);
    }

    public function test_direct_mode_runs_locally_and_does_not_touch_ssh(): void
    {
        $ssh = $this->fakeSsh();
        $local = new FakeLocalCommandRunner(['pg', 'dump']);
        $this->app->instance(LocalCommandRunner::class, $local);
        $backup = $this->pending(BackupJob::factory()->postgres('direct')->create());

        RunBackup::dispatchSync($backup);

        $backup->refresh();
        $this->assertSame(Backup::STATUS_SUCCESS, $backup->status);
        $this->assertSame('pgdump', Storage::disk('backups')->get($backup->file_path));
        $this->assertSame([], $ssh->calls);
        $this->assertCount(1, $local->calls);
        $this->assertSame(['PGPASSWORD' => 'db-secret-pass'], $local->calls[0]['env']);
        $this->assertStringNotContainsString('db-secret-pass', $local->calls[0]['command']);
        $this->assertStringContainsString('--host=db.example.com', $local->calls[0]['command']);
    }

    public function test_nonzero_exit_code_fails_backup_and_removes_partial_file(): void
    {
        $this->fakeSsh(['partial'], ['exit_code' => 2, 'stderr' => "tar: /var/www: Permission denied\n"]);
        $backup = $this->pending(BackupJob::factory()->create());

        RunBackup::dispatchSync($backup);

        $backup->refresh();
        $this->assertSame(Backup::STATUS_FAILED, $backup->status);
        $this->assertStringContainsString('кодом 2', $backup->error);
        $this->assertStringContainsString('Permission denied', $backup->error);
        $this->assertNull($backup->file_path);
        $this->assertNull($backup->sha256);
        $this->assertSame([], Storage::disk('backups')->allFiles());
        $this->assertNotNull($backup->finished_at);
    }

    public function test_empty_output_fails(): void
    {
        $this->fakeSsh([], ['exit_code' => 0, 'stderr' => '']);
        $backup = $this->pending(BackupJob::factory()->create());

        RunBackup::dispatchSync($backup);

        $this->assertSame(Backup::STATUS_FAILED, $backup->refresh()->status);
        $this->assertSame([], Storage::disk('backups')->allFiles());
    }

    public function test_connection_error_fails_backup(): void
    {
        $fake = $this->fakeSsh();
        $fake->throw = new SshConnectionException('Не удалось подключиться');
        $backup = $this->pending(BackupJob::factory()->create());

        RunBackup::dispatchSync($backup);

        $backup->refresh();
        $this->assertSame(Backup::STATUS_FAILED, $backup->status);
        $this->assertSame('Не удалось подключиться', $backup->error);
    }

    public function test_db_password_is_redacted_from_error_and_log(): void
    {
        $this->fakeSsh([], ['exit_code' => 1, 'stderr' => 'mysqldump: Access denied (password: db-secret-pass)']);
        $backup = $this->pending(BackupJob::factory()->mysql()->create());

        RunBackup::dispatchSync($backup);

        $backup->refresh();
        $this->assertStringNotContainsString('db-secret-pass', (string) $backup->error);
        $this->assertStringNotContainsString('db-secret-pass', (string) $backup->log);
        $this->assertStringContainsString('password: ***', $backup->error);
    }

    public function test_job_does_nothing_for_non_pending_backup(): void
    {
        $fake = $this->fakeSsh();
        $backup = BackupJob::factory()->create()->backups()->create(['status' => Backup::STATUS_SUCCESS]);

        RunBackup::dispatchSync($backup);

        $this->assertSame([], $fake->calls);
    }

    public function test_failed_hook_closes_hanging_backup(): void
    {
        $backup = BackupJob::factory()->create()->backups()->create(['status' => Backup::STATUS_RUNNING, 'started_at' => now()]);

        (new RunBackup($backup))->failed(new \RuntimeException('timeout'));

        $backup->refresh();
        $this->assertSame(Backup::STATUS_FAILED, $backup->status);
        $this->assertSame('timeout', $backup->error);
    }

    public function test_deleting_backup_removes_file_and_deleting_server_cascades(): void
    {
        $this->fakeSsh();
        $job = BackupJob::factory()->create();
        $backup = $this->pending($job);
        RunBackup::dispatchSync($backup);
        $path = $backup->refresh()->file_path;
        Storage::disk('backups')->assertExists($path);

        Server::find($job->server_id)->delete();

        Storage::disk('backups')->assertMissing($path);
        $this->assertDatabaseCount('backup_jobs', 0);
        $this->assertDatabaseCount('backups', 0);
    }
}
