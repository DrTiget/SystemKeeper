<?php

namespace Tests\Feature;

use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class ScheduledBackupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->app->instance(SshConnectorInterface::class, new FakeSshConnector(['exit_code' => 0, 'stdout' => '', 'stderr' => '']));
        config(['backup.timezone' => 'Europe/Moscow']);
        // источники настроены заранее, накануне
        $this->at('2026-10-14 12:00');
    }

    private function at(string $moscow): void
    {
        $this->travelTo(Carbon::parse($moscow, 'Europe/Moscow'));
    }

    private function server(bool $enabled, string $schedule = '0 3 * * *', array $paths = ['/var/www']): Server
    {
        $server = Server::factory()->create();
        $server->backupSettings()->create(['enabled' => $enabled, 'paths' => $paths, 'excludes' => [], 'schedule' => $schedule, 'pre_command_timeout' => 60]);

        return $server;
    }

    public function test_runs_due_backups_in_backup_timezone(): void
    {
        $due = $this->server(true);
        $this->server(false);
        $this->server(true, '0 4 * * *');
        $this->server(true, '0 3 * * *', []);

        $this->at('2026-10-15 03:00:20');
        $this->artisan('backups:dispatch-due')->assertSuccessful();

        $this->assertSame([$due->id], Backup::pluck('source_id')->all());
        $this->assertSame(Backup::TRIGGER_SCHEDULE, Backup::first()->trigger);
        Queue::assertPushed(RunBackup::class, 1);

        // повторные вызовы — пока бекап в очереди и после того, как он закончился, — не дублируют
        $this->artisan('backups:dispatch-due');
        Backup::query()->update(['status' => Backup::SUCCESS]);
        $this->at('2026-10-15 03:05');
        $this->artisan('backups:dispatch-due');
        $this->assertSame(1, Backup::count());
    }

    public function test_databases_with_enabled_backup_are_scheduled(): void
    {
        $db = DatabaseConnection::factory()->create(['backup_enabled' => true, 'schedule' => '*/15 * * * *']);
        DatabaseConnection::factory()->create(['backup_enabled' => false, 'schedule' => '*/15 * * * *']);

        $this->at('2026-10-15 10:30');
        $this->artisan('backups:dispatch-due');
        $this->at('2026-10-15 10:31');
        $this->artisan('backups:dispatch-due');

        $this->assertSame([$db->id], Backup::pluck('source_id')->all());
        $this->assertSame('database', Backup::first()->source_type);
    }

    public function test_missed_run_is_caught_up_once_within_six_hours(): void
    {
        $server = $this->server(true);

        // в 03:00 планировщик не работал (обновление, простой) — запуск догоняется при первой возможности
        $this->at('2026-10-15 05:42');
        $this->artisan('backups:dispatch-due')->expectsOutputToContain('пропущенный запуск 15.10 03:00');
        Backup::query()->update(['status' => Backup::SUCCESS]);
        $this->at('2026-10-15 05:43');
        $this->artisan('backups:dispatch-due');

        $this->assertSame(1, $server->backups()->count());
    }

    public function test_old_missed_run_is_not_caught_up(): void
    {
        $server = $this->server(true);

        $this->at('2026-10-15 09:01');
        $this->artisan('backups:dispatch-due');

        $this->assertSame(0, $server->backups()->count());
    }

    public function test_schedule_enabled_after_due_time_waits_for_next_run(): void
    {
        $this->at('2026-10-15 04:00');
        $server = $this->server(true);

        $this->artisan('backups:dispatch-due');
        $this->assertSame(0, $server->backups()->count());

        $this->at('2026-10-16 03:00');
        $this->artisan('backups:dispatch-due');
        $this->assertSame(1, $server->backups()->count());
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('backups:dispatch-due');
    }
}
