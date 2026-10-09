<?php

namespace Tests\Feature;

use App\Exceptions\Storage\StorageException;
use App\Jobs\UploadFileToStorageTargets;
use App\Models\StorageCopy;
use App\Models\StorageTarget;
use App\Models\User;
use App\Services\Storage\StorageUploaderInterface;
use App\Services\Storage\YandexDiskClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class YandexDiskUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config(['yandex_disk.backoff_ms' => 0, 'yandex_disk.attempts' => 3, 'yandex_disk.verify_attempts' => 2]);
        $this->file = tempnam(sys_get_temp_dir(), 'sk-up-');
        file_put_contents($this->file, str_repeat('backup-data', 1000));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function fakeDisk(?string $md5 = null, array $extra = []): void
    {
        Http::fake($extra + [
            'cloud-api.yandex.net/v1/disk/resources/upload*' => Http::response(['href' => 'https://uploader.example/put/1']),
            'uploader.example/*' => Http::response('', 201),
            'cloud-api.yandex.net/v1/disk/resources?path=%2F*' => Http::response(['md5' => $md5 ?? md5_file($this->file)]),
            'cloud-api.yandex.net/v1/disk/resources*' => fn (Request $r) => $r->method() === 'PUT'
                ? Http::response(['href' => 'x'], 201)
                : Http::response(['md5' => $md5 ?? md5_file($this->file)]),
        ]);
    }

    public function test_uploads_creates_folders_and_verifies_md5(): void
    {
        $this->fakeDisk();

        $md5 = (new YandexDiskClient('tok'))->upload($this->file, '/SystemKeeper/db/2026/dump.sql.gz');

        $this->assertSame(md5_file($this->file), $md5);
        $created = [];
        Http::assertSent(function (Request $r) use (&$created) {
            if ($r->method() === 'PUT' && str_contains($r->url(), '/resources?')) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
                $created[] = $q['path'];
            }

            return true;
        });
        $this->assertSame(['/SystemKeeper', '/SystemKeeper/db', '/SystemKeeper/db/2026'], $created);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://uploader.example/put/1' && $r->method() === 'PUT' && ! $r->hasHeader('Authorization'));
    }

    public function test_md5_mismatch_fails(): void
    {
        $this->fakeDisk(str_repeat('0', 32));

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Контрольная сумма не совпала');
        (new YandexDiskClient('tok'))->upload($this->file, '/a/b.bin');
    }

    public function test_retries_on_429_and_5xx_then_succeeds(): void
    {
        $this->fakeDisk(null, [
            'cloud-api.yandex.net/v1/disk/resources/upload*' => Http::sequence()
                ->push('', 429)->push('', 503)->push(['href' => 'https://uploader.example/put/1']),
        ]);

        $md5 = (new YandexDiskClient('tok'))->upload($this->file, '/b.bin');

        $this->assertSame(md5_file($this->file), $md5);
        Http::assertSentCount(5); // 3 запроса ссылки, PUT, md5 (папка — корень, не создаётся)
    }

    public function test_gives_up_after_attempts_and_does_not_retry_4xx(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 503)->push('', 503)->push('', 503)->push(['description' => 'bad'], 401)]);
        try {
            (new YandexDiskClient('tok'))->quota();
            $this->fail('Ожидалось исключение');
        } catch (StorageException $e) {
            $this->assertSame(503, $e->status);
        }
        Http::assertSentCount(3);

        try {
            (new YandexDiskClient('tok'))->quota();
            $this->fail('Ожидалось исключение');
        } catch (StorageException $e) {
            $this->assertSame(401, $e->status);
        }
        Http::assertSentCount(4);
    }

    public function test_uploader_records_copies_per_target_and_isolates_failures(): void
    {
        $good = StorageTarget::factory()->create(['name' => 'good', 'token' => 'good-token']);
        $bad = StorageTarget::factory()->create(['name' => 'bad', 'token' => 'bad-token']);
        $user = User::factory()->create();
        $this->fakeDisk(null, [
            'cloud-api.yandex.net/v1/disk/resources/upload*' => fn (Request $r) => $r->hasHeader('Authorization', 'OAuth bad-token')
                ? Http::response(['description' => 'no space'], 507)
                : Http::response(['href' => 'https://uploader.example/put/1']),
        ]);

        $copies = app(StorageUploaderInterface::class)->upload($this->file, [$good, $bad], 'srv/dump.gz', $user);

        $this->assertCount(2, $copies);
        $ok = StorageCopy::where('storage_target_id', $good->id)->first();
        $this->assertSame('uploaded', $ok->status);
        $this->assertSame(md5_file($this->file), $ok->md5);
        $this->assertSame('/SystemKeeper/srv/dump.gz', $ok->remote_path);
        $this->assertSame(filesize($this->file), $ok->size);
        $this->assertTrue($ok->uploadable->is($user));

        $fail = StorageCopy::where('storage_target_id', $bad->id)->first();
        $this->assertSame('failed', $fail->status);
        $this->assertStringContainsString('507', $fail->error);
        $this->assertStringNotContainsString('bad-token', $fail->error);
    }

    public function test_uploader_skips_already_uploaded_copy_on_rerun(): void
    {
        $target = StorageTarget::factory()->create();
        $this->fakeDisk();
        $uploader = app(StorageUploaderInterface::class);

        $uploader->upload($this->file, [$target], 'a.bin');
        Http::assertSentCount(4); // папка /SystemKeeper, ссылка, PUT, md5

        $uploader->upload($this->file, [$target], 'a.bin');
        Http::assertSentCount(4);
        $this->assertDatabaseCount('storage_copies', 1);
    }

    public function test_local_target_copies_and_verifies(): void
    {
        $dir = sys_get_temp_dir().'/sk-store-'.uniqid();
        $target = StorageTarget::factory()->local($dir)->create();

        app(StorageUploaderInterface::class)->upload($this->file, [$target], 'x/y.bin');

        $this->assertFileEquals($this->file, "{$dir}/x/y.bin");
        $this->assertSame('uploaded', StorageCopy::first()->status);
        unlink("{$dir}/x/y.bin");
        rmdir("{$dir}/x");
        rmdir($dir);
    }

    public function test_dispatch_queues_job_and_job_uploads_enabled_targets(): void
    {
        $on = StorageTarget::factory()->local(sys_get_temp_dir().'/sk-job-'.uniqid())->create();
        $off = StorageTarget::factory()->local(sys_get_temp_dir().'/sk-job-off-'.uniqid())->create(['enabled' => false]);

        Queue::fake();
        app(StorageUploaderInterface::class)->dispatch($this->file, [$on, $off->id], 'f.bin');
        Queue::assertPushed(UploadFileToStorageTargets::class, fn ($j) => $j->targetIds === [$on->id, $off->id] && $j->remotePath === 'f.bin');

        (new UploadFileToStorageTargets($this->file, [$on->id, $off->id], 'f.bin'))->handle(app(StorageUploaderInterface::class));

        $this->assertDatabaseCount('storage_copies', 1);
        $this->assertFileExists($on->base_path.'/f.bin');
        unlink($on->base_path.'/f.bin');
        rmdir($on->base_path);
    }

    public function test_job_throws_when_a_target_failed_so_queue_retries(): void
    {
        $target = StorageTarget::factory()->create();
        Http::fake(['*' => Http::response('', 401)]);

        $this->expectException(\RuntimeException::class);
        (new UploadFileToStorageTargets($this->file, [$target->id], 'f.bin'))->handle(app(StorageUploaderInterface::class));
    }
}
