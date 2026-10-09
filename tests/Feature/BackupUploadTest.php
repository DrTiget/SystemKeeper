<?php

namespace Tests\Feature;

use App\Exceptions\Storage\StorageException;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\Server;
use App\Models\StorageCopy;
use App\Models\StorageTarget;
use App\Models\User;
use App\Services\Ssh\SshConnectorInterface;
use App\Services\Storage\StorageUploader;
use App\Services\Storage\StorageUploaderInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\Support\FakeStorageUploader;
use Tests\TestCase;

class BackupUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $targetDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        $this->targetDir = sys_get_temp_dir().'/sk-target-'.bin2hex(random_bytes(4));
        $ssh = new FakeSshConnector;
        $ssh->streamChunks = ['backup-', 'data'];
        $this->app->instance(SshConnectorInterface::class, $ssh);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->targetDir)) {
            exec('rm -rf '.escapeshellarg($this->targetDir));
        }
        parent::tearDown();
    }

    private function fakeUploader(array $failDeleteFor = []): FakeStorageUploader
    {
        $fake = new FakeStorageUploader($failDeleteFor);
        $this->app->instance(StorageUploaderInterface::class, $fake);

        return $fake;
    }

    private function runBackup(BackupJob $job): Backup
    {
        $backup = $job->backups()->create(['status' => Backup::STATUS_PENDING]);
        RunBackup::dispatchSync($backup);

        return $backup->refresh();
    }

    public function test_successful_backup_is_dispatched_to_enabled_targets_only(): void
    {
        $fake = $this->fakeUploader();
        $a = StorageTarget::factory()->create();
        $b = StorageTarget::factory()->create();
        $off = StorageTarget::factory()->create(['enabled' => false]);
        $other = StorageTarget::factory()->create();
        $job = BackupJob::factory()->create(['storage_target_ids' => [$a->id, $b->id, $off->id]]);

        $backup = $this->runBackup($job);

        $this->assertCount(1, $fake->dispatched);
        $call = $fake->dispatched[0];
        $this->assertSame(Storage::disk('backups')->path($backup->file_path), $call['path']);
        $this->assertSame($backup->file_path, $call['remote']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $call['targets']);
        $this->assertSame(Backup::class.'#'.$backup->id, $call['owner']);
        $this->assertNotContains($other->id, $call['targets']);

        // копии «pending» видны сразу, до работы воркера
        $this->assertSame(2, $backup->copies()->where('status', 'pending')->count());
        $this->assertSame($a->resolvePath($backup->file_path), $backup->copies()->where('storage_target_id', $a->id)->value('remote_path'));
    }

    public function test_nothing_is_dispatched_without_targets_or_for_failed_backup(): void
    {
        $fake = $this->fakeUploader();
        $target = StorageTarget::factory()->create();

        $this->runBackup(BackupJob::factory()->create());
        $this->runBackup(BackupJob::factory()->create(['storage_target_ids' => []]));
        $this->runBackup(BackupJob::factory()->create(['storage_target_ids' => [$target->id + 100]]));

        $ssh = new FakeSshConnector;
        $ssh->streamResult = ['exit_code' => 1, 'stderr' => 'boom'];
        $ssh->streamChunks = ['x'];
        $this->app->instance(SshConnectorInterface::class, $ssh);
        $failed = $this->runBackup(BackupJob::factory()->create(['storage_target_ids' => [$target->id]]));

        $this->assertSame(Backup::STATUS_FAILED, $failed->status);
        $this->assertSame([], $fake->dispatched);
        $this->assertDatabaseCount('storage_copies', 0);
    }

    public function test_dispatch_failure_does_not_fail_the_backup(): void
    {
        $target = StorageTarget::factory()->create();
        $this->app->instance(StorageUploaderInterface::class, new class extends FakeStorageUploader
        {
            public function dispatch(string $localPath, iterable $targets, string $remotePath, ?Model $uploadable = null): void
            {
                throw new \RuntimeException('очередь недоступна');
            }
        });

        $backup = $this->runBackup(BackupJob::factory()->create(['storage_target_ids' => [$target->id]]));

        $this->assertSame(Backup::STATUS_SUCCESS, $backup->status);
        $this->assertStringContainsString('очередь недоступна', $backup->log);
    }

    public function test_end_to_end_uploads_to_local_target_with_real_uploader(): void
    {
        $this->app->bind(StorageUploaderInterface::class, StorageUploader::class);
        $target = StorageTarget::factory()->local($this->targetDir)->create();
        $job = BackupJob::factory()->create(['storage_target_ids' => [$target->id]]);

        $backup = $this->runBackup($job);

        $copy = $backup->copies()->sole();
        $this->assertSame(StorageCopy::STATUS_UPLOADED, $copy->status);
        $this->assertSame("{$this->targetDir}/{$backup->file_path}", $copy->remote_path);
        $this->assertSame('backup-data', file_get_contents($copy->remote_path));
        $this->assertSame($backup->md5, $copy->md5);
        $this->assertSame($backup->size_bytes, $copy->size);
    }

    public function test_api_returns_copies_with_statuses(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $target = StorageTarget::factory()->create(['name' => 'ya-main']);
        $backup = Backup::factory()->success('1/1/a.tar.zst')->create();
        StorageCopy::create([
            'uploadable_type' => $backup->getMorphClass(), 'uploadable_id' => $backup->id, 'storage_target_id' => $target->id,
            'remote_path' => '/SystemKeeper/1/1/a.tar.zst', 'status' => 'uploaded', 'size' => 9, 'md5' => md5('zstd-data'), 'attempts' => 1, 'uploaded_at' => now(),
        ]);

        $this->getJson("/api/v1/backups/{$backup->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.copies')
            ->assertJsonPath('data.copies.0.status', 'uploaded')
            ->assertJsonPath('data.copies.0.storage_target.name', 'ya-main')
            ->assertJsonPath('data.copies.0.remote_path', '/SystemKeeper/1/1/a.tar.zst')
            ->assertJsonPath('data.copies.0.md5', md5('zstd-data'));

        $this->getJson('/api/v1/backups')->assertOk()->assertJsonPath('data.0.copies.0.status', 'uploaded');
    }

    public function test_deleting_backup_removes_remote_copies_first(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $fake = $this->fakeUploader();
        $target = StorageTarget::factory()->create();
        Storage::disk('backups')->put('1/1/a.tar.zst', 'x');
        $backup = Backup::factory()->success('1/1/a.tar.zst')->create();
        $copy = $backup->copies()->create(['storage_target_id' => $target->id, 'remote_path' => '/SystemKeeper/a', 'status' => 'uploaded']);

        $this->deleteJson("/api/v1/backups/{$backup->id}")->assertNoContent();

        $this->assertSame([$copy->id], $fake->deleted);
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
        $this->assertDatabaseCount('storage_copies', 0);
        Storage::disk('backups')->assertMissing('1/1/a.tar.zst');
    }

    public function test_remote_delete_failure_keeps_backup_and_file(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $target = StorageTarget::factory()->create(['name' => 'ya-main']);
        Storage::disk('backups')->put('1/1/a.tar.zst', 'x');
        $backup = Backup::factory()->success('1/1/a.tar.zst')->create();
        $copy = $backup->copies()->create(['storage_target_id' => $target->id, 'remote_path' => '/SystemKeeper/a', 'status' => 'uploaded']);
        $this->fakeUploader([$copy->id]);

        $this->deleteJson("/api/v1/backups/{$backup->id}")
            ->assertStatus(502)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'ya-main') && str_contains($m, 'хранилище недоступно'));

        $this->assertDatabaseHas('backups', ['id' => $backup->id]);
        $this->assertDatabaseHas('storage_copies', ['id' => $copy->id]);
        Storage::disk('backups')->assertExists('1/1/a.tar.zst');
    }

    public function test_keep_remote_skips_storage_deletion(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $fake = $this->fakeUploader();
        $target = StorageTarget::factory()->create();
        $backup = Backup::factory()->success('1/1/a.tar.zst')->create();
        $backup->copies()->create(['storage_target_id' => $target->id, 'remote_path' => '/SystemKeeper/a', 'status' => 'uploaded']);

        $this->deleteJson("/api/v1/backups/{$backup->id}?keep_remote=1")->assertNoContent();

        $this->assertSame([], $fake->deleted);
        $this->assertDatabaseCount('storage_copies', 0);
    }

    public function test_backup_with_pending_upload_cannot_be_deleted_until_stale(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->fakeUploader();
        $target = StorageTarget::factory()->create();
        $backup = Backup::factory()->success('1/1/a.tar.zst')->create();
        $copy = $backup->copies()->create(['storage_target_id' => $target->id, 'remote_path' => '/SystemKeeper/a', 'status' => 'pending']);

        $this->deleteJson("/api/v1/backups/{$backup->id}")->assertConflict();

        $copy->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();
        $this->deleteJson("/api/v1/backups/{$backup->id}")->assertNoContent();
    }

    public function test_deleting_job_removes_copies_of_all_its_backups(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $fake = $this->fakeUploader();
        $target = StorageTarget::factory()->create();
        $job = BackupJob::factory()->create();
        foreach ([1, 2] as $n) {
            Backup::factory()->success()->create(['backup_job_id' => $job->id])
                ->copies()->create(['storage_target_id' => $target->id, 'remote_path' => "/SystemKeeper/{$n}", 'status' => 'uploaded']);
        }

        $this->deleteJson("/api/v1/backup-jobs/{$job->id}")->assertNoContent();

        $this->assertCount(2, $fake->deleted);
        $this->assertDatabaseCount('backups', 0);
        $this->assertDatabaseCount('storage_copies', 0);
    }

    public function test_job_validates_storage_target_ids_exist(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $target = StorageTarget::factory()->create();
        $base = ['name' => 'x', 'server_id' => Server::factory()->create()->id, 'type' => 'files', 'paths' => ['/etc']];

        $this->postJson('/api/v1/backup-jobs', $base + ['storage_target_ids' => [$target->id, 9999]])
            ->assertUnprocessable()->assertJsonValidationErrors(['storage_target_ids.1']);
        $this->postJson('/api/v1/backup-jobs', $base + ['storage_target_ids' => [$target->id]])
            ->assertCreated()->assertJsonPath('data.storage_target_ids', [$target->id]);
    }

    public function test_real_uploader_deletes_local_copy_only_inside_base_path(): void
    {
        mkdir($this->targetDir.'/1', 0777, true);
        file_put_contents($this->targetDir.'/1/a', 'x');
        $outside = sys_get_temp_dir().'/sk-outside-'.bin2hex(random_bytes(3));
        file_put_contents($outside, 'keep');
        $target = StorageTarget::factory()->local($this->targetDir)->create();
        $inside = $target->copies()->create(['remote_path' => $this->targetDir.'/1/a', 'status' => 'uploaded']);
        $evil = $target->copies()->create(['remote_path' => $outside, 'status' => 'uploaded']);
        $uploader = new StorageUploader;

        $uploader->deleteCopy($inside);
        $uploader->deleteCopy($inside); // повторно: файла нет — не ошибка
        $this->assertFileDoesNotExist($this->targetDir.'/1/a');

        try {
            $uploader->deleteCopy($evil);
            $this->fail('Удаление вне базовой папки должно быть отклонено.');
        } catch (StorageException) {
            $this->assertFileExists($outside);
        } finally {
            @unlink($outside);
        }
    }

    public function test_real_uploader_deletes_yandex_disk_copy(): void
    {
        Http::fake(['cloud-api.yandex.net/*' => Http::response('', 204)]);
        $target = StorageTarget::factory()->create();
        $copy = $target->copies()->create(['remote_path' => '/SystemKeeper/1/1/a.tar.zst', 'status' => 'uploaded']);

        (new StorageUploader)->deleteCopy($copy);

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains(urldecode($r->url()), 'path=/SystemKeeper/1/1/a.tar.zst'));
    }
}
