<?php

namespace Tests\Feature;

use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\Server;
use App\Models\StorageTarget;
use App\Models\User;
use App\Services\Backup\BackupRotator;
use App\Services\Ssh\SshConnectorInterface;
use App\Services\Storage\StorageUploaderInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\Support\FakeStorageUploader;
use Tests\TestCase;

class BackupRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        Carbon::setTestNow('2026-10-09 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Успешный бекап, завершённый $daysAgo суток назад (можно дробно). */
    private function backup(BackupJob $job, float $daysAgo, string $status = 'success'): Backup
    {
        $at = now()->subSeconds((int) ($daysAgo * 86400));

        return Backup::factory()->create([
            'backup_job_id' => $job->id, 'status' => $status, 'created_at' => $at, 'started_at' => $at, 'finished_at' => $at,
            'file_path' => $status === 'success' ? "{$job->server_id}/{$job->id}/".$at->format('Ymd-His').'.tar.zst' : null,
        ]);
    }

    /** @return list<int> id бекапов к удалению, по возрастанию */
    private function planned(BackupJob $job): array
    {
        return app(BackupRotator::class)->plan($job)->pluck('id')->sort()->values()->all();
    }

    private function ids(Backup ...$backups): array
    {
        return collect($backups)->pluck('id')->sort()->values()->all();
    }

    public function test_without_rules_nothing_is_deleted(): void
    {
        $job = BackupJob::factory()->create();
        $this->backup($job, 100);
        $this->backup($job, 200, 'failed');

        $this->assertSame([], $this->planned($job));
    }

    public function test_retention_count_keeps_newest(): void
    {
        $job = BackupJob::factory()->create(['retention_count' => 3]);
        $b = collect([1, 2, 3, 4, 5])->map(fn ($d) => $this->backup($job, $d));

        $this->assertSame($this->ids($b[3], $b[4]), $this->planned($job));
    }

    public function test_retention_days_keeps_recent_and_always_the_newest(): void
    {
        $job = BackupJob::factory()->create(['retention_days' => 3]);
        $fresh = $this->backup($job, 1);
        $this->backup($job, 2.5);
        $old1 = $this->backup($job, 4);
        $old2 = $this->backup($job, 10);

        $this->assertSame($this->ids($old1, $old2), $this->planned($job));

        // все бекапы старше срока — самый свежий всё равно остаётся
        $job2 = BackupJob::factory()->create(['retention_days' => 1]);
        $newest = $this->backup($job2, 5);
        $older = $this->backup($job2, 6);

        $this->assertSame($this->ids($older), $this->planned($job2));
        $this->assertNotContains($newest->id, $this->planned($job2));
        $this->assertNotContains($fresh->id, $this->planned($job));
    }

    public function test_gfs_daily_keeps_newest_backup_of_each_recent_day(): void
    {
        $job = BackupJob::factory()->create(['keep_daily' => 3]);
        // два бекапа в день: утренний и вечерний, за 5 суток (день 0 — сегодня, 12:00)
        $kept = [];
        $dropped = [];
        foreach ([0, 1, 2, 3, 4] as $d) {
            $evening = $this->backup($job, $d + 0.1); // позже в своём дне (свежее)
            $morning = $this->backup($job, $d + 0.3);
            $d < 3 ? $kept[] = $evening : $dropped[] = $evening;
            $dropped[] = $morning;
        }

        $this->assertSame($this->ids(...$dropped), $this->planned($job));
        $this->assertNotContains($kept[0]->id, $this->planned($job));
    }

    public function test_gfs_counts_only_days_with_backups(): void
    {
        $job = BackupJob::factory()->create(['keep_daily' => 2]);
        $a = $this->backup($job, 0.2);
        $b = $this->backup($job, 10.2); // между ними пустые дни — не считаются
        $c = $this->backup($job, 20.2);

        $this->assertSame($this->ids($c), $this->planned($job));
        $this->assertNotContains($a->id, $this->planned($job));
        $this->assertNotContains($b->id, $this->planned($job));
    }

    public function test_gfs_weekly_and_monthly(): void
    {
        // 2026-10-09 — пятница. Недели ISO: 41 (05–11 окт), 40 (28 сен–04 окт), 39 (21–27 сен)
        $job = BackupJob::factory()->create(['keep_weekly' => 2, 'keep_monthly' => 2]);
        $thisWeekNew = $this->backup($job, 0.5);   // 9 окт
        $thisWeekOld = $this->backup($job, 2.5);   // 7 окт, та же неделя
        $prevWeek = $this->backup($job, 7.5);      // 1 окт (неделя 40, октябрь)
        $prevMonth = $this->backup($job, 12.5);    // 26 сен (неделя 39, сентябрь)
        $prevMonthOld = $this->backup($job, 20);   // 19 сен (сентябрь, старше)
        $twoMonths = $this->backup($job, 45);      // 25 авг

        // weekly 2: недели 41 и 40 -> thisWeekNew, prevWeek; monthly 2: окт -> thisWeekNew, сен -> prevMonth
        $this->assertSame($this->ids($thisWeekOld, $prevMonthOld, $twoMonths), $this->planned($job));
        $this->assertNotContains($prevMonth->id, $this->planned($job));
    }

    public function test_rules_are_combined_with_or(): void
    {
        $job = BackupJob::factory()->create(['retention_count' => 1, 'retention_days' => 2, 'keep_monthly' => 2]);
        $newest = $this->backup($job, 0.5);
        $recent = $this->backup($job, 1.5);          // по retention_days
        $monthEnd = $this->backup($job, 12);         // 27 сен: самый свежий за сентябрь (monthly)
        $other = $this->backup($job, 14);            // 25 сен: тот же месяц, но старше

        $planned = $this->planned($job);

        $this->assertNotContains($newest->id, $planned);
        $this->assertNotContains($recent->id, $planned);
        $this->assertNotContains($monthEnd->id, $planned);
        $this->assertSame($this->ids($other), $planned);
    }

    public function test_old_failed_backups_are_removed_only_with_rules_and_active_are_untouched(): void
    {
        $withRules = BackupJob::factory()->create(['retention_count' => 5]);
        $this->backup($withRules, 1);
        $oldFailed = $this->backup($withRules, 8, 'failed');
        $newFailed = $this->backup($withRules, 2, 'failed');
        $running = $this->backup($withRules, 30, 'running');
        $pending = $this->backup($withRules, 30, 'pending');

        $noRules = BackupJob::factory()->create();
        $this->backup($noRules, 100, 'failed');

        $this->assertSame($this->ids($oldFailed), $this->planned($withRules));
        $this->assertSame([], $this->planned($noRules));
        $this->assertNotContains($running->id, $this->planned($withRules));
        $this->assertNotContains($pending->id, $this->planned($withRules));
        $this->assertNotContains($newFailed->id, $this->planned($withRules));
    }

    public function test_dry_run_deletes_nothing_and_real_run_removes_files_rows_and_remote_copies(): void
    {
        $uploader = new FakeStorageUploader;
        $this->app->instance(StorageUploaderInterface::class, $uploader);
        $target = StorageTarget::factory()->create();
        $job = BackupJob::factory()->create(['retention_count' => 1]);
        $new = $this->backup($job, 1);
        $old = $this->backup($job, 2);
        Storage::disk('backups')->put($new->file_path, 'n');
        Storage::disk('backups')->put($old->file_path, 'o');
        $copy = $old->copies()->create(['storage_target_id' => $target->id, 'remote_path' => '/SystemKeeper/old', 'status' => 'uploaded']);
        $rotator = app(BackupRotator::class);

        $dry = $rotator->rotate($job, dryRun: true);

        $this->assertSame(['dry_run' => true, 'kept' => 1, 'deleted' => [$old->id], 'errors' => []], $dry);
        $this->assertDatabaseHas('backups', ['id' => $old->id]);
        Storage::disk('backups')->assertExists($old->file_path);
        $this->assertSame([], $uploader->deleted);

        $real = $rotator->rotate($job);

        $this->assertSame([$old->id], $real['deleted']);
        $this->assertSame(1, $real['kept']);
        $this->assertDatabaseMissing('backups', ['id' => $old->id]);
        Storage::disk('backups')->assertMissing($old->file_path);
        Storage::disk('backups')->assertExists($new->file_path);
        $this->assertSame([$copy->id], $uploader->deleted);
        $this->assertDatabaseCount('storage_copies', 0);
    }

    public function test_remote_failure_keeps_backup_and_reports_error_while_others_are_rotated(): void
    {
        $target = StorageTarget::factory()->create();
        $job = BackupJob::factory()->create(['retention_count' => 1]);
        $this->backup($job, 1);
        $stuck = $this->backup($job, 2);
        $gone = $this->backup($job, 3);
        $copy = $stuck->copies()->create(['storage_target_id' => $target->id, 'remote_path' => '/SystemKeeper/x', 'status' => 'uploaded']);
        $this->app->instance(StorageUploaderInterface::class, new FakeStorageUploader([$copy->id]));

        $result = app(BackupRotator::class)->rotate($job);

        $this->assertSame([$gone->id], $result['deleted']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('хранилище недоступно', $result['errors'][0]);
        $this->assertDatabaseHas('backups', ['id' => $stuck->id]);
        $this->assertDatabaseHas('storage_copies', ['id' => $copy->id]);
    }

    public function test_command_dry_run_and_real_run_with_job_filter(): void
    {
        $this->app->instance(StorageUploaderInterface::class, new FakeStorageUploader);
        $a = BackupJob::factory()->create(['retention_count' => 1]);
        $b = BackupJob::factory()->create(['retention_count' => 1]);
        $noRules = BackupJob::factory()->create();
        foreach ([$a, $b, $noRules] as $job) {
            $this->backup($job, 1);
            $this->backup($job, 2);
        }

        $this->artisan('backups:rotate', ['--dry-run' => true])
            ->expectsOutputToContain("Задание #{$a->id}")
            ->expectsOutputToContain('будет удалено 1')
            ->assertSuccessful();
        $this->assertDatabaseCount('backups', 6);

        $this->artisan('backups:rotate', ['--job' => $a->id])->expectsOutputToContain('удалено 1')->assertSuccessful();
        $this->assertSame(1, $a->backups()->count());
        $this->assertSame(2, $b->backups()->count());

        $this->artisan('backups:rotate')->assertSuccessful();
        $this->assertSame(1, $b->backups()->count());
        $this->assertSame(2, $noRules->backups()->count());
    }

    public function test_command_fails_when_remote_deletion_fails(): void
    {
        $target = StorageTarget::factory()->create();
        $job = BackupJob::factory()->create(['retention_count' => 1]);
        $this->backup($job, 1);
        $copy = $this->backup($job, 2)->copies()->create(['storage_target_id' => $target->id, 'remote_path' => '/x', 'status' => 'uploaded']);
        $this->app->instance(StorageUploaderInterface::class, new FakeStorageUploader([$copy->id]));

        $this->artisan('backups:rotate')->assertFailed();
    }

    public function test_rotation_is_scheduled_daily(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");

        $this->assertStringContainsString('backups:rotate', $commands);
    }

    public function test_api_rotate_endpoint_supports_dry_run(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->app->instance(StorageUploaderInterface::class, new FakeStorageUploader);
        $job = BackupJob::factory()->create(['retention_count' => 1]);
        $this->backup($job, 1);
        $old = $this->backup($job, 2);

        $this->postJson("/api/v1/backup-jobs/{$job->id}/rotate", ['dry_run' => true])
            ->assertOk()->assertJsonPath('data.dry_run', true)->assertJsonPath('data.deleted', [$old->id]);
        $this->assertDatabaseHas('backups', ['id' => $old->id]);

        $this->postJson("/api/v1/backup-jobs/{$job->id}/rotate")
            ->assertOk()->assertJsonPath('data.dry_run', false)->assertJsonPath('data.deleted', [$old->id]);
        $this->assertDatabaseMissing('backups', ['id' => $old->id]);

        $this->postJson("/api/v1/backup-jobs/{$job->id}/rotate", ['dry_run' => 'maybe'])->assertUnprocessable();
    }

    public function test_api_rotate_requires_auth(): void
    {
        $this->postJson('/api/v1/backup-jobs/1/rotate')->assertUnauthorized();
    }

    public function test_job_api_accepts_gfs_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();

        $this->postJson('/api/v1/backup-jobs', [
            'name' => 'g', 'server_id' => $server->id, 'type' => 'files', 'paths' => ['/etc'],
            'keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 12, 'retention_count' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.keep_daily', 7)->assertJsonPath('data.keep_weekly', 4)->assertJsonPath('data.keep_monthly', 12);

        $this->postJson('/api/v1/backup-jobs', [
            'name' => 'g2', 'server_id' => $server->id, 'type' => 'files', 'paths' => ['/etc'], 'keep_daily' => 0, 'keep_monthly' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors(['keep_daily', 'keep_monthly']);
    }

    public function test_rotation_runs_right_after_successful_backup(): void
    {
        $ssh = new FakeSshConnector;
        $ssh->streamChunks = ['data'];
        $this->app->instance(SshConnectorInterface::class, $ssh);
        $this->app->instance(StorageUploaderInterface::class, new FakeStorageUploader);
        $job = BackupJob::factory()->create(['retention_count' => 2]);
        $old1 = $this->backup($job, 3);
        $old2 = $this->backup($job, 2);
        $newest = $this->backup($job, 1);
        $fresh = $job->backups()->create(['status' => Backup::STATUS_PENDING]);

        RunBackup::dispatchSync($fresh);

        $this->assertSame('success', $fresh->refresh()->status);
        $this->assertDatabaseMissing('backups', ['id' => $old1->id]);
        $this->assertDatabaseMissing('backups', ['id' => $old2->id]);
        $this->assertDatabaseHas('backups', ['id' => $newest->id]);
        $this->assertDatabaseHas('backups', ['id' => $fresh->id]);
    }
}
