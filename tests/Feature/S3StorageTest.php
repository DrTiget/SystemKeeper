<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\BackupStorage;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\Server;
use App\Models\User;
use App\Services\Backup\StorageFilesystems;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class S3StorageTest extends TestCase
{
    use RefreshDatabase;

    private Filesystem $bucket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        $this->bucket = Storage::fake('s3-bucket');
        // бакет S3 — фейковый диск, локальное хранилище — как обычно
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
    }

    private function createS3(array $attrs = []): int
    {
        return $this->postJson('/api/v1/storages', [
            'type' => 's3', 'name' => 'Яндекс', 'endpoint' => 'https://storage.yandexcloud.net', 'region' => 'ru-central1',
            'bucket' => 'my-backups', 'prefix' => '/sk/', 'key' => 'AKID', 'secret' => 'top-secret', ...$attrs,
        ])->assertCreated()->json('data.id');
    }

    public function test_create_hides_secret_and_encrypts_settings(): void
    {
        $id = $this->createS3();

        $this->getJson("/api/v1/storages/{$id}")
            ->assertOk()
            ->assertJsonPath('data.type', 's3')
            ->assertJsonPath('data.disk', null)
            ->assertJsonPath('data.s3', [
                'endpoint' => 'https://storage.yandexcloud.net', 'region' => 'ru-central1', 'bucket' => 'my-backups',
                'prefix' => 'sk', 'path_style' => false, 'key' => 'AKID', 'has_secret' => true,
            ])
            ->assertDontSee('top-secret');
        $this->assertStringNotContainsString('top-secret', (string) DB::table('backup_storages')->where('id', $id)->value('settings'));

        // без secret — остаётся прежний
        $this->patchJson("/api/v1/storages/{$id}", ['bucket' => 'other-bucket'])->assertOk()->assertJsonPath('data.s3.has_secret', true);
        $this->assertSame('top-secret', BackupStorage::find($id)->settings['secret']);
        $this->assertSame('other-bucket', BackupStorage::find($id)->settings['bucket']);
    }

    public function test_validation(): void
    {
        $this->postJson('/api/v1/storages', ['type' => 'ftp', 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['type', 'bucket', 'key', 'secret']);
        $this->postJson('/api/v1/storages', ['type' => 's3', 'name' => 'x', 'bucket' => 'Bad_Bucket', 'key' => 'k', 'secret' => 's', 'endpoint' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors(['bucket', 'endpoint']);
        $local = BackupStorage::default();
        $this->patchJson("/api/v1/storages/{$local->id}", ['bucket' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('bucket');
    }

    public function test_backup_is_uploaded_to_bucket_downloaded_and_deleted(): void
    {
        $id = $this->createS3();
        $server = Server::factory()->create();
        $server->backupSettings()->create(['enabled' => false, 'paths' => ['/var/www'], 'excludes' => [], 'schedule' => '0 3 * * *', 'pre_command_timeout' => 60, 'storage_id' => $id]);

        $bid = $this->postJson("/api/v1/servers/{$server->id}/backups")->assertStatus(202)->json('data.id');
        $backup = Backup::find($bid);

        $this->assertSame(Backup::SUCCESS, $backup->status);
        $this->assertSame($id, $backup->storage_id);
        $this->bucket->assertExists($backup->path.'/files.tar.gz');
        $this->assertSame('TARDATA', $this->bucket->get($backup->path.'/files.tar.gz'));
        Storage::disk('backups')->assertMissing($backup->path);
        $this->assertContains('Загружено', array_column($backup->log, 'message'));

        $this->getJson("/api/v1/backups/{$bid}/files/files.tar.gz")->assertOk()->assertDownload('files.tar.gz');

        $this->getJson("/api/v1/storages/{$id}")->assertJsonPath('data.used', 7)->assertJsonPath('data.sources.0.id', $server->id);
        $this->deleteJson("/api/v1/storages/{$id}")->assertStatus(409);

        $this->deleteJson("/api/v1/backups/{$bid}")->assertNoContent();
        $this->bucket->assertMissing($backup->path.'/files.tar.gz');
    }

    public function test_destroy_rules_and_check(): void
    {
        $id = $this->createS3();
        $this->postJson("/api/v1/storages/{$id}/check")->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertSame([], $this->bucket->allFiles());

        $this->deleteJson('/api/v1/storages/'.BackupStorage::default()->id)->assertStatus(409);
        $this->deleteJson("/api/v1/storages/{$id}")->assertNoContent();
    }

    public function test_sources_include_project_and_group(): void
    {
        $group = ProjectGroup::create(['name' => 'Клиенты']);
        $project = Project::factory()->create(['name' => 'Магазин', 'group_id' => $group->id]);
        $server = Server::factory()->create(['project_id' => $project->id]);

        $this->getJson('/api/v1/storages/'.BackupStorage::default()->id)
            ->assertJsonPath('data.sources.0.project', ['id' => $project->id, 'name' => 'Магазин', 'group' => ['id' => $group->id, 'name' => 'Клиенты']]);
    }
}
