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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class BackupManifestTest extends TestCase
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

        $s3 = BackupStorage::create(['name' => 'Яндекс', 'type' => 's3', 'settings' => ['bucket' => 'b', 'key' => 'k', 'secret' => 's']]);
        $group = ProjectGroup::create(['name' => 'Клиенты']);
        $project = Project::factory()->create(['name' => 'Магазин', 'group_id' => $group->id]);
        $this->server = Server::factory()->create(['name' => 'web-1', 'project_id' => $project->id]);
        $this->server->backupSettings()->create([
            'enabled' => false, 'paths' => ['/var/www'], 'excludes' => ['*.log'], 'schedule' => '0 3 * * *', 'pre_command_timeout' => 60,
            'storage_ids' => [$s3->id], 'keep_count' => 2,
        ]);
    }

    private function runBackup(): Backup
    {
        return Backup::findOrFail($this->postJson("/api/v1/servers/{$this->server->id}/backups")->assertStatus(202)->json('data.id'));
    }

    private function index(): array
    {
        return json_decode($this->bucket->get('index.json'), true);
    }

    public function test_backup_info_index_and_readme_are_written(): void
    {
        $backup = $this->runBackup();
        $key = "servers/{$this->server->id}";

        $info = json_decode($this->bucket->get($backup->path.'/backup.json'), true);
        $this->assertSame('web-1', $info['source']['name']);
        $this->assertSame(['id' => $this->server->project_id, 'name' => 'Магазин', 'group' => 'Клиенты'], $info['source']['project']);
        $this->assertSame(hash('sha256', 'TARDATA'), $info['files'][0]['sha256']);
        $this->assertStringContainsString('tar -xzf files.tar.gz -C /', implode("\n", $info['restore']));

        $entry = $this->index()['sources'][$key];
        $this->assertSame('web-1', $entry['name']);
        $this->assertSame(['/var/www'], $entry['paths']);
        $this->assertSame([$backup->path], array_column($entry['backups'], 'directory'));

        $readme = $this->bucket->get('README.md');
        $this->assertStringContainsString("## `{$key}/` — Сервер «web-1» · проект «Магазин» (группа «Клиенты»)", $readme);
        $this->assertStringContainsString('`'.basename($backup->path).'`', $readme);
    }

    public function test_manual_edits_are_preserved_on_update(): void
    {
        $this->runBackup();
        $key = "servers/{$this->server->id}";

        // кто-то правит файлы руками: своя запись, своё поле в нашей записи, заметка в README
        $index = $this->index();
        $index['sources']['manual/old-server'] = ['name' => 'Старый сервер, бекапы вручную'];
        $index['sources'][$key]['note'] = 'ключ от сервера — у Ивана';
        $index['owner'] = 'admin@example.com';
        $this->bucket->put('index.json', json_encode($index));
        $this->bucket->put('README.md', "# Наши бекапы\n\nЗаметка сверху.\n\n".explode("\n", $this->bucket->get('README.md'), 3)[2]."\nЗаметка снизу.\n");

        $second = $this->runBackup();

        $index = $this->index();
        $this->assertSame('Старый сервер, бекапы вручную', $index['sources']['manual/old-server']['name']);
        $this->assertSame('ключ от сервера — у Ивана', $index['sources'][$key]['note']);
        $this->assertSame('admin@example.com', $index['owner']);
        $this->assertCount(2, $index['sources'][$key]['backups']);
        $this->assertSame($second->path, $index['sources'][$key]['backups'][0]['directory']);

        $readme = $this->bucket->get('README.md');
        $this->assertStringStartsWith("# Наши бекапы\n\nЗаметка сверху.", $readme);
        $this->assertStringContainsString('Заметка снизу.', $readme);
        $this->assertSame(1, substr_count($readme, 'systemkeeper:begin'));
        $this->assertStringContainsString('manual/old-server', $readme);
    }

    public function test_retention_and_deletion_update_index(): void
    {
        $first = $this->runBackup();
        $this->runBackup();
        $third = $this->runBackup(); // keep_count = 2 — первый вытеснен
        $key = "servers/{$this->server->id}";

        $dirs = array_column($this->index()['sources'][$key]['backups'], 'directory');
        $this->assertNotContains($first->path, $dirs);
        $this->assertCount(2, $dirs);

        $this->deleteJson("/api/v1/backups/{$third->id}")->assertNoContent();
        $this->assertNotContains($third->path, array_column($this->index()['sources'][$key]['backups'], 'directory'));

        // последний бекап удалён — записи источника в оглавлении больше нет
        $this->deleteJson('/api/v1/backups/'.$this->server->backups()->first()->id)->assertNoContent();
        $this->assertArrayNotHasKey($key, $this->index()['sources']);
    }

    public function test_broken_index_is_kept_aside(): void
    {
        $this->bucket->put('index.json', '{ это не json');
        $this->runBackup();

        $this->assertArrayHasKey("servers/{$this->server->id}", $this->index()['sources']);
        $broken = collect($this->bucket->files())->first(fn ($f) => str_starts_with($f, 'index.json.broken-'));
        $this->assertNotNull($broken);
        $this->assertSame('{ это не json', $this->bucket->get($broken));
    }
}
