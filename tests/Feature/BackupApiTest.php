<?php

namespace Tests\Feature;

use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
    }

    private function login(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_requires_authentication(): void
    {
        $job = BackupJob::factory()->create();
        $backup = Backup::factory()->create();

        $this->getJson('/api/v1/backup-jobs')->assertUnauthorized();
        $this->postJson('/api/v1/backup-jobs', [])->assertUnauthorized();
        $this->getJson("/api/v1/backup-jobs/{$job->id}")->assertUnauthorized();
        $this->putJson("/api/v1/backup-jobs/{$job->id}", [])->assertUnauthorized();
        $this->deleteJson("/api/v1/backup-jobs/{$job->id}")->assertUnauthorized();
        $this->postJson("/api/v1/backup-jobs/{$job->id}/run")->assertUnauthorized();
        $this->getJson('/api/v1/backups')->assertUnauthorized();
        $this->getJson("/api/v1/backups/{$backup->id}")->assertUnauthorized();
        $this->getJson("/api/v1/backups/{$backup->id}/download")->assertUnauthorized();
        $this->deleteJson("/api/v1/backups/{$backup->id}")->assertUnauthorized();
    }

    public function test_creates_files_job(): void
    {
        $this->login();
        $server = Server::factory()->create();

        $this->postJson('/api/v1/backup-jobs', [
            'name' => 'www', 'server_id' => $server->id, 'type' => 'files',
            'paths' => ['/var/www'], 'excludes' => ['*.log'], 'schedule' => '0 3 * * *',
            'retention_count' => 7, 'retention_days' => 30, 'storage_target_ids' => [1, 2],
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'files')
            ->assertJsonPath('data.paths', ['/var/www'])
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.db_mode', null)
            ->assertJsonPath('data.retention_count', 7)
            ->assertJsonPath('data.storage_target_ids', [1, 2])
            ->assertJsonPath('data.server.id', $server->id)
            ->assertJsonPath('data.latest_backup', null);
    }

    public function test_files_job_requires_paths_and_valid_cron(): void
    {
        $this->login();
        $server = Server::factory()->create();

        $this->postJson('/api/v1/backup-jobs', ['name' => 'x', 'server_id' => $server->id, 'type' => 'files', 'schedule' => 'bad cron'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['paths', 'schedule']);

        $this->postJson('/api/v1/backup-jobs', ['name' => 'x', 'server_id' => $server->id, 'type' => 'files', 'paths' => ['relative/path']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['paths.0']);

        $this->postJson('/api/v1/backup-jobs', ['name' => 'x', 'server_id' => 999, 'type' => 'zip'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['server_id', 'type']);
    }

    public function test_creates_mysql_job_without_exposing_password(): void
    {
        $this->login();
        $server = Server::factory()->create();

        $response = $this->postJson('/api/v1/backup-jobs', [
            'name' => 'db', 'server_id' => $server->id, 'type' => 'mysql',
            'db_name' => 'shop', 'db_user' => 'dumper', 'db_password' => 'top-secret',
            'paths' => ['/ignored'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.db_mode', 'ssh')
            ->assertJsonPath('data.has_db_password', true)
            ->assertJsonPath('data.paths', null);

        $this->assertStringNotContainsString('top-secret', $response->getContent());
        $this->assertSame('top-secret', BackupJob::first()->db_password);
        $this->assertNotSame('top-secret', \DB::table('backup_jobs')->value('db_password'));
    }

    public function test_direct_mode_requires_host_and_user(): void
    {
        $this->login();
        $server = Server::factory()->create();

        $this->postJson('/api/v1/backup-jobs', ['name' => 'db', 'server_id' => $server->id, 'type' => 'postgres', 'db_mode' => 'direct'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['db_host', 'db_user']);

        $this->postJson('/api/v1/backup-jobs', [
            'name' => 'db', 'server_id' => $server->id, 'type' => 'postgres', 'db_mode' => 'direct',
            'db_host' => 'db.local', 'db_user' => 'u', 'db_name' => 'app',
        ])->assertCreated()->assertJsonPath('data.db_mode', 'direct');
    }

    public function test_rejects_unsafe_database_name(): void
    {
        $this->login();
        $server = Server::factory()->create();

        $this->postJson('/api/v1/backup-jobs', ['name' => 'db', 'server_id' => $server->id, 'type' => 'mysql', 'db_name' => '--all; rm -rf /'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['db_name']);
    }

    public function test_updates_job_keeping_type_and_password(): void
    {
        $this->login();
        $job = BackupJob::factory()->mysql()->create();

        $this->putJson("/api/v1/backup-jobs/{$job->id}", ['name' => 'renamed', 'enabled' => false, 'schedule' => '*/5 * * * *'])
            ->assertOk()
            ->assertJsonPath('data.name', 'renamed')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.next_run_at', null)
            ->assertJsonPath('data.has_db_password', true);

        $this->assertSame('db-secret-pass', $job->refresh()->db_password);
        $this->assertSame('mysql', $job->type);
    }

    public function test_update_direct_mode_validates_against_existing_values(): void
    {
        $this->login();
        $job = BackupJob::factory()->mysql()->create(['db_host' => null]);

        $this->patchJson("/api/v1/backup-jobs/{$job->id}", ['db_mode' => 'direct'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['db_host']);
    }

    public function test_lists_filters_and_shows_jobs(): void
    {
        $this->login();
        $files = BackupJob::factory()->create(['name' => 'a']);
        BackupJob::factory()->mysql()->create(['name' => 'b']);
        Backup::factory()->success()->create(['backup_job_id' => $files->id]);

        $this->getJson('/api/v1/backup-jobs')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/backup-jobs?type=mysql')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'b');
        $this->getJson("/api/v1/backup-jobs?server_id={$files->server_id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/backup-jobs/{$files->id}")
            ->assertOk()
            ->assertJsonPath('data.backups_count', 1)
            ->assertJsonPath('data.latest_backup.status', 'success');
    }

    public function test_run_creates_pending_backup_and_dispatches_job(): void
    {
        Queue::fake();
        $this->login();
        $job = BackupJob::factory()->create();

        $this->postJson("/api/v1/backup-jobs/{$job->id}/run")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.trigger', 'manual');

        Queue::assertPushed(RunBackup::class, 1);
        $this->assertDatabaseCount('backups', 1);

        // повторный запуск, пока предыдущий не завершён
        $this->postJson("/api/v1/backup-jobs/{$job->id}/run")->assertConflict();
        Queue::assertPushed(RunBackup::class, 1);
    }

    public function test_deleting_job_removes_backups_and_files_but_not_while_running(): void
    {
        $this->login();
        $job = BackupJob::factory()->create();
        Storage::disk('backups')->put('1/1/a.tar.zst', 'data');
        Backup::factory()->success('1/1/a.tar.zst')->create(['backup_job_id' => $job->id]);

        $running = Backup::factory()->running()->create(['backup_job_id' => $job->id]);
        $this->deleteJson("/api/v1/backup-jobs/{$job->id}")->assertConflict();
        Storage::disk('backups')->assertExists('1/1/a.tar.zst');

        $running->delete();
        $this->deleteJson("/api/v1/backup-jobs/{$job->id}")->assertNoContent();
        Storage::disk('backups')->assertMissing('1/1/a.tar.zst');
        $this->assertDatabaseCount('backups', 0);
    }

    public function test_lists_and_shows_backups(): void
    {
        $this->login();
        $job = BackupJob::factory()->create();
        $ok = Backup::factory()->success('1/1/a.tar.zst')->create(['backup_job_id' => $job->id, 'log' => 'details']);
        Backup::factory()->failed()->create(['backup_job_id' => $job->id]);
        Backup::factory()->create();

        $this->getJson('/api/v1/backups')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/backups?backup_job_id={$job->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/backups?status=failed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.error', 'boom');

        $list = $this->getJson("/api/v1/backups?backup_job_id={$job->id}&status=success")->assertOk();
        $this->assertArrayNotHasKey('log', $list->json('data.0'));
        $this->assertStringNotContainsString('1/1/a.tar.zst', $list->getContent());

        $this->getJson("/api/v1/backups/{$ok->id}")
            ->assertOk()
            ->assertJsonPath('data.sha256', hash('sha256', 'zstd-data'))
            ->assertJsonPath('data.size_bytes', 9)
            ->assertJsonPath('data.file_name', 'a.tar.zst')
            ->assertJsonPath('data.log', 'details')
            ->assertJsonPath('data.backup_job.id', $job->id);
    }

    public function test_downloads_backup_file(): void
    {
        $this->login();
        Storage::disk('backups')->put('1/1/a.tar.zst', 'zstd-data');
        $backup = Backup::factory()->success('1/1/a.tar.zst')->create();

        $response = $this->get("/api/v1/backups/{$backup->id}/download");

        $response->assertOk()->assertDownload('a.tar.zst');
        $response->assertHeader('X-Checksum-Sha256', hash('sha256', 'zstd-data'));
    }

    public function test_download_returns_404_when_file_missing_or_backup_not_successful(): void
    {
        $this->login();
        $missing = Backup::factory()->success('1/1/gone.tar.zst')->create();
        $failed = Backup::factory()->failed()->create();

        $this->getJson("/api/v1/backups/{$missing->id}/download")->assertNotFound();
        $this->getJson("/api/v1/backups/{$failed->id}/download")->assertNotFound();
    }

    public function test_deletes_backup_with_file_but_not_active_one(): void
    {
        $this->login();
        Storage::disk('backups')->put('1/1/a.tar.zst', 'zstd-data');
        $backup = Backup::factory()->success('1/1/a.tar.zst')->create();
        $running = Backup::factory()->running()->create();

        $this->deleteJson("/api/v1/backups/{$backup->id}")->assertNoContent();
        Storage::disk('backups')->assertMissing('1/1/a.tar.zst');
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);

        $this->deleteJson("/api/v1/backups/{$running->id}")->assertConflict();
        $this->assertDatabaseHas('backups', ['id' => $running->id]);
    }

    public function test_scheduler_command_queues_only_due_jobs(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-09 12:00:30');

        $due = BackupJob::factory()->create(['schedule' => '* * * * *', 'created_at' => '2026-10-09 11:00:00']);
        $notYet = BackupJob::factory()->create(['schedule' => '0 3 * * *', 'created_at' => '2026-10-09 11:00:00', 'last_scheduled_at' => '2026-10-09 03:00:05']);
        $disabled = BackupJob::factory()->create(['schedule' => '* * * * *', 'enabled' => false, 'created_at' => '2026-10-09 11:00:00']);
        $manual = BackupJob::factory()->create(['schedule' => null, 'created_at' => '2026-10-09 11:00:00']);
        $busy = BackupJob::factory()->create(['schedule' => '* * * * *', 'created_at' => '2026-10-09 11:00:00']);
        Backup::factory()->running()->create(['backup_job_id' => $busy->id]);

        $this->artisan('backups:run-due')->assertSuccessful();

        Queue::assertPushed(RunBackup::class, 1);
        $this->assertSame(Backup::TRIGGER_SCHEDULE, $due->backups()->first()->trigger);
        $this->assertCount(0, $notYet->backups);
        $this->assertCount(0, $disabled->backups);
        $this->assertCount(0, $manual->backups);
        $this->assertCount(1, $busy->backups);
        $this->assertNotNull($busy->refresh()->last_scheduled_at);

        // повторный запуск в ту же минуту ничего не добавляет
        $this->artisan('backups:run-due')->assertSuccessful();
        Queue::assertPushed(RunBackup::class, 1);

        Carbon::setTestNow();
    }

    public function test_scheduler_does_not_run_new_job_for_past_cron_times(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-09 12:00:30');
        BackupJob::factory()->create(['schedule' => '0 3 * * *', 'created_at' => '2026-10-09 11:59:00']);

        $this->artisan('backups:run-due')->assertSuccessful();

        Queue::assertNothingPushed();
        Carbon::setTestNow();
    }

    public function test_scheduler_fails_stale_active_backups(): void
    {
        Queue::fake();
        Storage::disk('backups')->put('1/1/partial.tar.zst', 'x');
        $stale = Backup::factory()->running()->create(['file_path' => '1/1/partial.tar.zst', 'created_at' => now()->subDay()]);
        $fresh = Backup::factory()->running()->create();

        $this->artisan('backups:run-due')->assertSuccessful();

        $this->assertSame(Backup::STATUS_FAILED, $stale->refresh()->status);
        Storage::disk('backups')->assertMissing('1/1/partial.tar.zst');
        $this->assertSame(Backup::STATUS_RUNNING, $fresh->refresh()->status);
    }
}
