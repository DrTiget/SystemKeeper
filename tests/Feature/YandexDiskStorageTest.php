<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\BackupStorage;
use App\Models\Server;
use App\Models\User;
use App\Services\Backup\TransferLinks;
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
            ->assertJsonPath('data.yandex_disk', ['folder' => 'disk:/Бекапы/Серверы', 'has_token' => true, 'parallel' => 4])
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

    private function serverBackingUpTo(int $storageId): Server
    {
        $server = Server::factory()->create();
        $server->backupSettings()->create(['enabled' => false, 'paths' => ['/var/www'], 'excludes' => [], 'schedule' => '0 3 * * *', 'pre_command_timeout' => 60, 'storage_ids' => [$storageId]]);

        return $server;
    }

    public function test_disk_fetches_backup_by_signed_link_when_systemkeeper_is_public(): void
    {
        config(['systemkeeper.public_url' => 'https://public.test', 'yandex_disk.poll_ms' => 0, 'yandex_disk.fetch_min_size' => 0]);
        $id = $this->create();
        $server = $this->serverBackingUpTo($id);

        $backup = Backup::find($this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id'));

        $this->assertSame(Backup::SUCCESS, $backup->status, (string) $backup->error);
        $file = "disk:/SystemKeeper/{$backup->path}/files.tar.gz";
        $this->assertSame('TARDATA', $this->disk->files[$file]);
        $this->assertSame([$file], $this->disk->fetched);
        $this->assertNotContains($file, $this->disk->uploaded);
    }

    public function test_falls_back_to_direct_upload_when_disk_cannot_fetch(): void
    {
        // адрес «публичный», но ссылку Диск открыть не смог
        config(['systemkeeper.public_url' => 'https://unreachable.test', 'yandex_disk.poll_ms' => 0, 'yandex_disk.fetch_min_size' => 0]);
        $id = $this->create();
        $server = $this->serverBackingUpTo($id);

        $backup = Backup::find($this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id'));

        $this->assertSame(Backup::SUCCESS, $backup->status, (string) $backup->error);
        $file = "disk:/SystemKeeper/{$backup->path}/files.tar.gz";
        $this->assertContains($file, $this->disk->uploaded);
        $this->assertTrue(collect($backup->log)->contains(fn ($l) => str_contains($l['message'], 'не скачал files.tar.gz по ссылке')));
    }

    public function test_local_address_uses_direct_upload_without_trying_link(): void
    {
        config(['systemkeeper.public_url' => null, 'app.url' => 'http://localhost:8080']);
        $this->assertNull(app(TransferLinks::class)->base());
        config(['app.url' => 'http://192.168.1.10']);
        $this->assertNull(app(TransferLinks::class)->base());
        config(['app.url' => 'https://sk.example.com/']);
        $this->assertSame('https://sk.example.com', app(TransferLinks::class)->base());
    }

    public function test_transfer_link_requires_valid_signature_and_backup_path(): void
    {
        config(['systemkeeper.public_url' => 'https://public.test']);
        $local = BackupStorage::default();
        Storage::disk('backups')->put('servers/1/2026-10-15_100000-1/files.tar.gz', 'DATA');
        Storage::disk('backups')->put('secret.txt', 'NO');
        $links = app(TransferLinks::class);

        $url = $links->for($local, 'servers/1/2026-10-15_100000-1/files.tar.gz');
        $this->assertStringStartsWith('https://public.test/api/v1/transfers/', $url);
        $relative = substr($url, strlen('https://public.test'));

        $this->app['auth']->forgetGuards();
        $this->get($relative)->assertOk()->assertHeader('Content-Length', '4');
        $this->get(str_replace('files.tar.gz', 'other.tar.gz', $relative))->assertForbidden();
        $this->get(strtok($relative, '?'))->assertForbidden();

        $other = substr($links->for($local, 'secret.txt'), strlen('https://public.test'));
        $this->get($other)->assertNotFound();

        $this->travel(4)->hours();
        $this->get($relative)->assertForbidden();
    }

    public function test_small_files_are_uploaded_directly_even_when_public(): void
    {
        config(['systemkeeper.public_url' => 'https://public.test', 'yandex_disk.poll_ms' => 0]);
        $server = $this->serverBackingUpTo($this->create());

        $backup = Backup::find($this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id'));

        $this->assertSame([], $this->disk->fetched);
        $this->assertContains("disk:/SystemKeeper/{$backup->path}/files.tar.gz", $this->disk->uploaded);
    }

    public function test_sync_runs_several_link_downloads_at_once(): void
    {
        config(['systemkeeper.public_url' => 'https://public.test', 'yandex_disk.poll_ms' => 0, 'yandex_disk.fetch_min_size' => 0]);
        $this->disk->operationPolls = 2;
        $server = Server::factory()->create();
        $server->backupSettings()->create(['enabled' => false, 'paths' => ['/var/www'], 'excludes' => [], 'schedule' => '0 3 * * *', 'pre_command_timeout' => 60]);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id');
            Backup::query()->update(['created_at' => now()->subMinutes(10 - $i)]);
        }
        $storage = $this->create(['parallel' => 3]);
        $this->getJson("/api/v1/storages/{$storage}")->assertJsonPath('data.yandex_disk.parallel', 3);

        $this->postJson("/api/v1/storages/{$storage}/sync")
            ->assertStatus(202)
            ->assertJsonPath('data.last.status', 'success')
            ->assertJsonPath('data.last.done', 5)
            ->assertJsonPath('data.last.bytes_done', 35);

        $this->assertSame(3, $this->disk->maxConcurrent);
        $this->assertCount(5, $this->disk->fetched);
        foreach (Backup::findMany($ids) as $b) {
            $this->assertArrayHasKey("disk:/SystemKeeper/{$b->path}/backup.json", $this->disk->files);
        }
    }

    public function test_parallel_is_validated(): void
    {
        $this->postJson('/api/v1/storages', ['type' => 'yandex_disk', 'name' => 'x', 'token' => 't', 'parallel' => 11])
            ->assertStatus(422)->assertJsonValidationErrors('parallel');
        $this->assertSame(4, BackupStorage::find($this->create())->settings['parallel']);
    }
}
