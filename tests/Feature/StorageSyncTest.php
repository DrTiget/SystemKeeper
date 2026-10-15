<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\BackupCopy;
use App\Models\BackupStorage;
use App\Models\Server;
use App\Models\User;
use App\Services\Backup\StorageFilesystems;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class StorageSyncTest extends TestCase
{
    use RefreshDatabase;

    private Filesystem $bucket;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        $this->bucket = Storage::fake('s3-bucket');
        $this->app->instance(StorageFilesystems::class, new class($this->bucket) extends StorageFilesystems
        {
            public function __construct(private Filesystem $bucket) {}

            public function for(BackupStorage $storage): Filesystem
            {
                return $storage->type === BackupStorage::S3 ? $this->bucket : parent::for($storage);
            }
        });
        $this->app->instance(SshConnectorInterface::class, new FakeSshConnector(['exit_code' => 0, 'stdout' => 'TARDATA', 'stderr' => '']));
        Sanctum::actingAs(User::factory()->create());

        $this->server = Server::factory()->create();
        $this->server->backupSettings()->create(['enabled' => false, 'paths' => ['/var/www'], 'excludes' => [], 'schedule' => '0 3 * * *', 'pre_command_timeout' => 60]);
    }

    private function s3(): BackupStorage
    {
        return BackupStorage::create(['type' => 's3', 'name' => 'Бакет', 'settings' => ['bucket' => 'b', 'key' => 'k', 'secret' => 's']]);
    }

    private function backup(): Backup
    {
        return Backup::find($this->postJson("/api/v1/servers/{$this->server->id}/backups")->assertStatus(202)->json('data.id'));
    }

    public function test_copies_existing_backups_to_new_storage(): void
    {
        $first = $this->backup();
        $second = $this->backup();
        $s3 = $this->s3();

        $this->getJson("/api/v1/storages/{$s3->id}/sync")
            ->assertOk()
            ->assertJsonPath('data.pending', ['backups' => 2, 'size' => 14])
            ->assertJsonPath('data.last', null);

        $this->postJson("/api/v1/storages/{$s3->id}/sync")
            ->assertStatus(202)
            ->assertJsonPath('data.last.status', 'success')
            ->assertJsonPath('data.last.total', 2)
            ->assertJsonPath('data.last.done', 2)
            ->assertJsonPath('data.last.bytes_done', 14)
            ->assertJsonPath('data.pending.backups', 0);

        foreach ([$first, $second] as $b) {
            $this->assertSame('TARDATA', $this->bucket->get($b->path.'/files.tar.gz'));
            $this->bucket->assertExists($b->path.'/backup.json');
            $this->assertTrue($b->copies()->where('storage_id', $s3->id)->where('status', BackupCopy::SUCCESS)->exists());
        }
        $this->bucket->assertExists('index.json');
        $this->bucket->assertExists('README.md');
        // локальные копии на месте, источник по-прежнему пишет только на диск
        Storage::disk('backups')->assertExists($first->path.'/files.tar.gz');
        $this->assertNull($this->server->backupSettings->fresh()->storage_ids ?: null);

        $this->postJson("/api/v1/storages/{$s3->id}/sync")->assertStatus(422)->assertJsonPath('message', 'Все бекапы уже есть в этом хранилище.');
    }

    public function test_add_to_sources_makes_new_backups_go_there_too(): void
    {
        $this->backup();
        $s3 = $this->s3();

        $this->postJson("/api/v1/storages/{$s3->id}/sync", ['add_to_sources' => true])->assertStatus(202);

        $local = BackupStorage::default()->id;
        $this->assertEquals([$local, $s3->id], $this->server->backupSettings->fresh()->storage_ids);
        $this->assertEqualsCanonicalizing([$local, $s3->id], $this->backup()->copies->pluck('storage_id')->all());
    }

    public function test_missing_file_fails_only_that_backup_and_retry_finishes(): void
    {
        $broken = $this->backup();
        $ok = $this->backup();
        $s3 = $this->s3();
        Storage::disk('backups')->delete($broken->path.'/files.tar.gz');

        $this->postJson("/api/v1/storages/{$s3->id}/sync")
            ->assertStatus(202)
            ->assertJsonPath('data.last.status', 'success')
            ->assertJsonPath('data.last.done', 1)
            ->assertJsonPath('data.last.failed', 1)
            ->assertJsonPath('data.pending.backups', 1);

        $this->assertStringContainsString($broken->path, $s3->syncs()->first()->error);
        $this->assertSame(BackupCopy::FAILED, $broken->copies()->where('storage_id', $s3->id)->value('status'));
        $this->bucket->assertExists($ok->path.'/files.tar.gz');

        // файл вернули — повторный запуск докопирует
        Storage::disk('backups')->put($broken->path.'/files.tar.gz', 'TARDATA');
        $this->postJson("/api/v1/storages/{$s3->id}/sync")->assertStatus(202)->assertJsonPath('data.last.done', 1)->assertJsonPath('data.pending.backups', 0);
        $this->assertSame(BackupCopy::SUCCESS, $broken->copies()->where('storage_id', $s3->id)->value('status'));
    }

    public function test_copies_from_s3_back_to_local_disk(): void
    {
        $s3 = $this->s3();
        $this->server->backupSettings->update(['storage_ids' => [$s3->id]]);
        $backup = $this->backup();
        Storage::disk('backups')->assertMissing($backup->path.'/files.tar.gz');

        $local = BackupStorage::default();
        $this->postJson("/api/v1/storages/{$local->id}/sync")->assertStatus(202)->assertJsonPath('data.last.done', 1);

        $this->assertSame('TARDATA', Storage::disk('backups')->get($backup->path.'/files.tar.gz'));
    }

    public function test_cannot_start_twice_and_failed_copies_do_not_block_deletion(): void
    {
        $this->backup();
        $s3 = $this->s3();
        $s3->syncs()->create(['status' => 'running']);

        $this->postJson("/api/v1/storages/{$s3->id}/sync")->assertStatus(422)->assertJsonPath('message', 'Синхронизация уже идёт.');

        $s3->syncs()->update(['status' => 'failed']);
        Backup::first()->copies()->create(['storage_id' => $s3->id, 'status' => BackupCopy::FAILED, 'error' => 'x']);
        $this->deleteJson("/api/v1/storages/{$s3->id}")->assertNoContent();
    }
}
