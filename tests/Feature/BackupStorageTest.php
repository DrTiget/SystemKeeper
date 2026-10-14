<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class BackupStorageTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private FakeSshConnector $ssh;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        Sanctum::actingAs(User::factory()->create());
        $this->ssh = new FakeSshConnector(['exit_code' => 0, 'stdout' => str_repeat('x', 1000), 'stderr' => '']);
        $this->app->instance(SshConnectorInterface::class, $this->ssh);

        $this->server = Server::factory()->create();
        $this->server->backupSettings()->create([
            'enabled' => false, 'paths' => ['/var/www'], 'excludes' => [], 'schedule' => '0 3 * * *', 'pre_command_timeout' => 60,
        ]);
    }

    private function runBackup(): Backup
    {
        $id = $this->postJson("/api/v1/servers/{$this->server->id}/backups")->assertStatus(202)->json('data.id');

        return Backup::findOrFail($id);
    }

    public function test_local_storage_exists_with_disk_info(): void
    {
        $this->getJson('/api/v1/storages')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'local')
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonStructure(['data' => [['disk' => ['total', 'free'], 'used', 'backups_count']]]);
    }

    public function test_retention_settings_are_saved_and_validated(): void
    {
        $storage = BackupStorage::default();
        $this->putJson("/api/v1/servers/{$this->server->id}/backup-settings", ['keep_count' => 3, 'max_size' => 10 * 1024 ** 2, 'storage_id' => $storage->id])
            ->assertOk()
            ->assertJsonPath('data.retention', ['storage_id' => $storage->id, 'keep_count' => 3, 'max_size' => 10 * 1024 ** 2]);

        $this->putJson("/api/v1/servers/{$this->server->id}/backup-settings", ['keep_count' => 0, 'max_size' => 100, 'storage_id' => 999])
            ->assertStatus(422)->assertJsonValidationErrors(['keep_count', 'max_size', 'storage_id']);

        $db = DatabaseConnection::factory()->create();
        $this->patchJson("/api/v1/databases/{$db->id}", ['keep_count' => 2, 'max_size' => null])
            ->assertOk()->assertJsonPath('data.retention.keep_count', 2);
    }

    public function test_keeps_only_last_n_successful_backups(): void
    {
        $this->server->backupSettings->update(['keep_count' => 2]);
        $first = $this->runBackup();
        $this->runBackup();
        $third = $this->runBackup();

        $this->assertModelMissing($first);
        Storage::disk('backups')->assertMissing($first->path);
        $this->assertSame(2, $this->server->backups()->count());
        $this->assertStringContainsString('хранится 2 последних', collect($third->fresh()->log)->last()['message']);
        $this->assertSame(BackupStorage::default()->id, $third->fresh()->storage_id);
    }

    public function test_size_limit_evicts_oldest_but_never_the_newest(): void
    {
        $this->server->backupSettings->update(['keep_count' => 10, 'max_size' => 2500]);
        $first = $this->runBackup();
        $this->runBackup();
        $this->runBackup();
        // 3 × 1000 > 2500 — самый старый вытеснен
        $this->assertModelMissing($first);
        $this->assertSame(2, $this->server->backups()->count());

        $this->server->backupSettings->update(['max_size' => 500]);
        $last = $this->runBackup();
        $this->assertSame([$last->id], $this->server->backups()->pluck('id')->all());
        $this->assertStringContainsString('больше лимита', collect($last->fresh()->log)->last()['message']);
    }

    public function test_failed_backup_files_are_removed_and_do_not_count(): void
    {
        $this->server->backupSettings->update(['keep_count' => 1]);
        $ok = $this->runBackup();

        $this->ssh->result = ['exit_code' => 2, 'stdout' => 'partial', 'stderr' => 'boom'];
        $failed = $this->runBackup()->fresh();

        $this->assertSame(Backup::FAILED, $failed->status);
        $this->assertSame([], $failed->files);
        Storage::disk('backups')->assertMissing($failed->path);
        // неудачный бекап не вытесняет последний успешный
        $this->assertModelExists($ok);
    }

    public function test_not_enough_free_space_fails_backup(): void
    {
        config(['backup.min_free_space' => PHP_INT_MAX]);
        $backup = $this->runBackup()->fresh();
        $this->assertSame(Backup::FAILED, $backup->status);
        $this->assertStringContainsString('меньше минимума', $backup->error);
        $this->assertCount(0, $this->ssh->calls);
    }

    public function test_storage_show_lists_sources_with_usage(): void
    {
        $this->runBackup();
        $db = DatabaseConnection::factory()->create(['keep_count' => 5]);
        $storage = BackupStorage::default();

        $this->getJson("/api/v1/storages/{$storage->id}")
            ->assertOk()
            ->assertJsonPath('data.used', 1000)
            ->assertJsonPath('data.backups_count', 1)
            ->assertJsonFragment(['type' => 'server', 'id' => $this->server->id, 'used' => 1000, 'backups_count' => 1, 'keep_count' => 7, 'max_size' => null])
            ->assertJsonFragment(['type' => 'database', 'id' => $db->id, 'used' => 0, 'keep_count' => 5]);

        $this->patchJson("/api/v1/storages/{$storage->id}", ['name' => 'Локально'])->assertOk()->assertJsonPath('data.name', 'Локально');
    }
}
