<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\Server;
use App\Models\StorageCopy;
use App\Models\StorageTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_empty_summary(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.servers.total', 0)
            ->assertJsonPath('data.backups.success_24h', 0)
            ->assertJsonPath('data.backups.last_failed', [])
            ->assertJsonPath('data.backups.stale_jobs', [])
            ->assertJsonPath('data.monitoring.total', 0)
            ->assertJsonPath('data.monitoring.uptime_24h', null)
            ->assertJsonPath('data.storages', []);
    }

    public function test_servers_block(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Server::factory()->create(['last_check_status' => 'ok']);
        Server::factory()->create(['last_check_status' => 'ok']);
        Server::factory()->create(['last_check_status' => 'failed']);
        Server::factory()->create();

        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.servers', ['total' => 4, 'check_ok' => 2, 'check_failed' => 1, 'unchecked' => 1]);
    }

    public function test_backups_block(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $job = BackupJob::factory()->create(['name' => 'daily', 'schedule' => '0 3 * * *']);
        BackupJob::factory()->create(['name' => 'hourly', 'schedule' => '0 * * * *']);
        BackupJob::factory()->create(['name' => 'manual', 'schedule' => null]);

        Backup::factory()->for($job, 'job')->success('a/1.tar.zst')->create(); // 24ч
        Backup::factory()->for($job, 'job')->success('a/2.tar.zst')->create(['finished_at' => now()->subDays(3)]);
        Backup::factory()->for($job, 'job')->success('a/3.tar.zst')->create(['finished_at' => now()->subDays(10)]); // вне 7д
        Backup::factory()->for($job, 'job')->failed('disk full')->create();
        Backup::factory()->for($job, 'job')->failed('old')->create(['finished_at' => now()->subDays(2)]);
        Backup::factory()->for($job, 'job')->running()->create();

        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.backups');

        $this->assertSame(1, $data['success_24h']);
        $this->assertSame(1, $data['failed_24h']);
        $this->assertSame(2, $data['success_7d']);
        $this->assertSame(2, $data['failed_7d']);
        $this->assertSame('disk full', $data['last_failed'][0]['error']);
        $this->assertSame('daily', $data['last_failed'][0]['job_name']);
        $this->assertCount(2, $data['last_failed']);
        $this->assertSame(['hourly', 'daily'], array_column($data['upcoming'], 'job_name'));
        $this->assertCount(2, $data['upcoming']);
        $this->assertSame(9 * 3, $data['local_size_bytes']);
    }

    public function test_stale_jobs(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $fresh = BackupJob::factory()->create(['name' => 'fresh']);
        $old = BackupJob::factory()->create(['name' => 'old']);
        $never = BackupJob::factory()->create(['name' => 'never', 'created_at' => now()->subDays(5)]);
        BackupJob::factory()->create(['name' => 'new']);
        BackupJob::factory()->create(['name' => 'off', 'enabled' => false, 'created_at' => now()->subDays(5)]);
        Backup::factory()->for($fresh, 'job')->success()->create();
        Backup::factory()->for($old, 'job')->success()->create(['finished_at' => now()->subDays(4)]);
        Backup::factory()->for($old, 'job')->failed()->create();

        $stale = $this->getJson('/api/v1/dashboard')->json('data.backups.stale_jobs');
        $this->assertEqualsCanonicalizing(['old', 'never'], array_column($stale, 'job_name'));

        $stale = $this->getJson('/api/v1/dashboard?stale_days=7')->json('data.backups.stale_jobs');
        $this->assertSame([], $stale);
    }

    public function test_size_by_storage(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $target = StorageTarget::factory()->create(['name' => 'disk']);
        StorageTarget::factory()->create(['name' => 'empty']);
        $backup = Backup::factory()->success('a/1.tar.zst')->create();
        foreach ([[StorageCopy::STATUS_UPLOADED, 100], [StorageCopy::STATUS_UPLOADED, 50], [StorageCopy::STATUS_FAILED, 999]] as [$status, $size]) {
            StorageCopy::create([
                'uploadable_type' => $backup->getMorphClass(), 'uploadable_id' => $backup->id,
                'storage_target_id' => $target->id, 'remote_path' => "x/{$size}", 'status' => $status, 'size' => $size,
            ]);
        }

        $by = $this->getJson('/api/v1/dashboard')->json('data.backups.size_by_storage');
        $this->assertCount(1, $by);
        $this->assertSame(['name' => 'disk', 'copies' => 2, 'size_bytes' => 150], [
            'name' => $by[0]['name'], 'copies' => $by[0]['copies'], 'size_bytes' => $by[0]['size_bytes'],
        ]);
    }

    public function test_monitoring_block(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $up = Monitor::factory()->create(['last_status' => 'up']);
        $down = Monitor::factory()->create(['name' => 'broken', 'last_status' => 'down']);
        Monitor::factory()->create(['enabled' => false, 'last_status' => 'down']);
        Monitor::factory()->create();

        foreach ([['up', $up], ['up', $up], ['up', $up], ['down', $up], ['down', $down]] as [$status, $m]) {
            MonitorCheck::create(['monitor_id' => $m->id, 'status' => $status, 'checked_at' => now()->subHour()]);
        }
        MonitorCheck::create(['monitor_id' => $up->id, 'status' => 'down', 'checked_at' => now()->subDays(10)]);
        Incident::create(['monitor_id' => $down->id, 'started_at' => now()->subMinutes(5), 'reason' => 'timeout']);
        Incident::create(['monitor_id' => $up->id, 'started_at' => now()->subDay(), 'resolved_at' => now()->subHours(20), 'duration_seconds' => 4 * 3600]);

        $data = $this->getJson('/api/v1/dashboard')->assertOk()->json('data.monitoring');

        $this->assertSame([4, 1, 1, 1, 1], [$data['total'], $data['up'], $data['down'], $data['paused'], $data['pending']]);
        $this->assertSame(1, $data['active_incidents_count']);
        $this->assertSame('broken', $data['active_incidents'][0]['monitor_name']);
        $this->assertSame('timeout', $data['active_incidents'][0]['reason']);
        // за 24ч: up 3/4 = 75%, down 0% → среднее 37.5%; за 30д у up 3 из 5
        $this->assertSame(37.5, $data['uptime_24h']);
        $this->assertEqualsWithDelta(((3 / 5 * 100) + 0) / 2, $data['uptime_30d'], 0.01);
    }

    public function test_storages_free_space_from_last_check(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $target = StorageTarget::factory()->create(['name' => 'local']);
        StorageTarget::factory()->create(['name' => 'unchecked']);
        $target->forceFill(['last_checked_at' => now(), 'last_check_status' => 'ok', 'last_total_space' => 1000, 'last_free_space' => 400])->save();

        $storages = $this->getJson('/api/v1/dashboard')->json('data.storages');
        $this->assertSame('local', $storages[0]['name']);
        $this->assertSame([1000, 400], [$storages[0]['total_space'], $storages[0]['free_space']]);
        $this->assertNull($storages[1]['free_space']);
    }

    public function test_check_persists_space(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $dir = sys_get_temp_dir().'/sk-dash-'.uniqid();
        mkdir($dir);
        $target = StorageTarget::factory()->create(['type' => 'local', 'base_path' => $dir]);

        $this->postJson("/api/v1/storage-targets/{$target->id}/check")->assertOk()->assertJsonPath('data.status', 'ok');

        $this->assertNotNull($target->refresh()->last_free_space);
        rmdir($dir);
    }

    public function test_query_count_does_not_grow_with_data(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/dashboard')->assertOk();

            return count(DB::getQueryLog());
        };
        $seed = function (int $n) {
            $job = BackupJob::factory()->create(['schedule' => '* * * * *']);
            BackupJob::factory()->count($n)->create();
            Backup::factory()->count($n)->for($job, 'job')->failed()->create();
            Monitor::factory()->count($n)->create(['last_status' => 'down'])
                ->each(fn ($m) => Incident::create(['monitor_id' => $m->id, 'started_at' => now()]));
            StorageTarget::factory()->count($n)->create();
        };
        $seed(1);
        $before = $count();
        $seed(6);

        $this->assertSame($before, $count());
    }
}
