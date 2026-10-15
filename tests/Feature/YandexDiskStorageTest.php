<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\BackupStorage;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\SshConnectorInterface;
use App\Services\Storage\YandexDiskClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\Support\FakeYandexDisk;
use Tests\TestCase;

class YandexDiskStorageTest extends TestCase
{
    use RefreshDatabase;

    private FakeYandexDisk $disk;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        config(['yandex_disk.base_url' => 'https://cloud-api.test/v1/disk', 'yandex_disk.backoff_ms' => 0]);
        $this->disk = (new FakeYandexDisk)->install();
        $this->app->instance(SshConnectorInterface::class, new FakeSshConnector(['exit_code' => 0, 'stdout' => 'TARDATA', 'stderr' => '']));
        Sanctum::actingAs(User::factory()->create());
    }

    private function create(array $attrs = []): int
    {
        return $this->postJson('/api/v1/storages', ['type' => 'yandex_disk', 'name' => 'Мой Диск', 'token' => 'y0_secret-token', ...$attrs])
            ->assertCreated()->json('data.id');
    }

    public function test_create_normalizes_folder_hides_token_and_shows_quota(): void
    {
        $id = $this->create(['folder' => 'Бекапы/Серверы/']);

        $this->getJson("/api/v1/storages/{$id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'yandex_disk')
            ->assertJsonPath('data.yandex_disk', ['folder' => 'disk:/Бекапы/Серверы', 'has_token' => true])
            ->assertJsonPath('data.s3', null)
            ->assertJsonPath('data.disk.total', 10 * 1024 ** 3)
            ->assertDontSee('y0_secret-token');
        $this->assertStringNotContainsString('y0_secret-token', (string) DB::table('backup_storages')->where('id', $id)->value('settings'));
        $this->assertSame('OAuth y0_secret-token', $this->disk->token);

        $this->assertSame('disk:/SystemKeeper', BackupStorage::find($this->create())->settings['folder']);
        $this->assertSame('app:/sk', BackupStorage::find($this->create(['folder' => 'app:/sk/']))->settings['folder']);

        // токен при изменении можно не передавать
        $this->patchJson("/api/v1/storages/{$id}", ['name' => 'Диск'])->assertOk()->assertJsonPath('data.yandex_disk.has_token', true);
    }

    public function test_validation(): void
    {
        $this->postJson('/api/v1/storages', ['type' => 'yandex_disk', 'name' => 'x', 'bucket' => 'b'])
            ->assertStatus(422)->assertJsonValidationErrors(['token', 'bucket']);
        $this->postJson('/api/v1/storages', ['type' => 'yandex_disk', 'name' => 'x', 'token' => 't', 'folder' => 'a:b*c'])
            ->assertStatus(422)->assertJsonValidationErrors('folder');
    }

    public function test_check_writes_reads_and_deletes(): void
    {
        $id = $this->create();

        $this->postJson("/api/v1/storages/{$id}/check")->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertTrue(isset($this->disk->dirs['disk:/SystemKeeper']));
        $this->assertSame([], $this->disk->files);
    }

    public function test_check_reports_bad_token(): void
    {
        $id = $this->create();
        $this->disk->failWith = [401, ['description' => 'Unauthorized']];

        $this->postJson("/api/v1/storages/{$id}/check")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Токен Яндекс Диска недействителен или отозван (401): Unauthorized');
    }

    public function test_backup_is_uploaded_downloaded_and_deleted(): void
    {
        $id = $this->create();
        $server = Server::factory()->create();
        $server->backupSettings()->create(['enabled' => false, 'paths' => ['/var/www'], 'excludes' => [], 'schedule' => '0 3 * * *', 'pre_command_timeout' => 60, 'storage_ids' => [$id]]);

        $bid = $this->postJson("/api/v1/servers/{$server->id}/backups")->assertStatus(202)->json('data.id');
        $backup = Backup::find($bid);

        $this->assertSame(Backup::SUCCESS, $backup->status, (string) $backup->error);
        $file = "disk:/SystemKeeper/{$backup->path}/files.tar.gz";
        $this->assertSame('TARDATA', $this->disk->files[$file]);
        $this->assertArrayHasKey("disk:/SystemKeeper/{$backup->path}/backup.json", $this->disk->files);
        $this->assertArrayHasKey('disk:/SystemKeeper/index.json', $this->disk->files);
        Storage::disk('backups')->assertMissing($backup->path);

        $this->assertSame('TARDATA', $this->getJson("/api/v1/backups/{$bid}/files/files.tar.gz")->assertOk()->streamedContent());

        $this->deleteJson("/api/v1/backups/{$bid}")->assertNoContent();
        $this->assertArrayNotHasKey($file, $this->disk->files);
    }

    public function test_upload_from_unseekable_stream_creates_folders(): void
    {
        $client = new YandexDiskClient('t');
        $stream = popen('printf "%s" '.escapeshellarg(str_repeat('x', 100_000)), 'r');

        $client->upload('disk:/SystemKeeper/a/b/c.bin', $stream);
        pclose($stream);

        $this->assertSame(100_000, strlen($this->disk->files['disk:/SystemKeeper/a/b/c.bin']));
        $this->assertTrue(isset($this->disk->dirs['disk:/SystemKeeper/a/b']));
        $this->assertSame(str_repeat('x', 100_000), stream_get_contents($client->download('disk:/SystemKeeper/a/b/c.bin')));
    }
}
