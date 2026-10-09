<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\StorageCopy;
use App\Models\StorageTarget;
use App\Services\Backup\BackupPurgeException;
use App\Services\Backup\BackupPurger;
use App\Services\Storage\StorageUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeStorageDriverFactory;
use Tests\TestCase;

class StorageCopiesLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'sk-life-');
        file_put_contents($this->file, 'payload');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_uploads_to_all_types_records_checksums_and_purge_deletes_copies(): void
    {
        $factory = new FakeStorageDriverFactory;
        $uploader = new StorageUploader($factory);
        $s3 = StorageTarget::factory()->s3()->create();
        $sftp = StorageTarget::factory()->sftp(null, '/srv/b')->create();
        $backup = Backup::factory()->success('srv/job/a.zst')->create();

        $copies = $uploader->upload($this->file, [$s3, $sftp], 'srv/job/a.zst', $backup);

        $this->assertSame(['uploaded', 'uploaded'], array_map(fn ($c) => $c->status, $copies));
        $this->assertSame(['/backups/srv/job/a.zst', '/srv/b/srv/job/a.zst'], array_map(fn ($c) => $c->remote_path, $copies));
        $this->assertSame(md5('payload'), $copies[0]->md5);
        $this->assertNull($copies[0]->sha256);
        $this->assertNull($copies[1]->md5);
        $this->assertSame(hash('sha256', 'payload'), $copies[1]->sha256);

        (new BackupPurger($uploader))->purge($backup->fresh());

        $deletes = array_values(array_filter($factory->calls, fn ($c) => $c['op'] === 'delete'));
        $this->assertSame(
            [[$s3->id, '/backups/srv/job/a.zst'], [$sftp->id, '/srv/b/srv/job/a.zst']],
            array_map(fn ($c) => [$c['target'], $c['path']], $deletes),
        );
        $this->assertDatabaseCount('storage_copies', 0);
        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    }

    public function test_purge_keeps_backup_when_remote_delete_fails(): void
    {
        $factory = new FakeStorageDriverFactory;
        $uploader = new StorageUploader($factory);
        $s3 = StorageTarget::factory()->s3()->create();
        $sftp = StorageTarget::factory()->sftp()->create();
        $backup = Backup::factory()->success('a.zst')->create();
        $uploader->upload($this->file, [$s3, $sftp], 'a.zst', $backup);
        $factory->failDeleteFor = [$sftp->id];

        try {
            (new BackupPurger($uploader))->purge($backup->fresh());
            $this->fail('Ожидалось исключение');
        } catch (BackupPurgeException $e) {
            $this->assertStringContainsString($sftp->name, $e->getMessage());
        }

        $this->assertDatabaseHas('backups', ['id' => $backup->id]);
        $this->assertSame(1, StorageCopy::count());
    }
}
