<?php

namespace Tests\Feature;

use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $off = $this->server(false);
        $later = $this->server(true, '0 4 * * *');
        $noPaths = $this->server(true, '0 3 * * *', []);

        $this->travelTo(now()->setTimezone('Europe/Moscow')->setTime(3, 0, 20));
        $this->artisan('backups:dispatch-due')->assertSuccessful();

        $this->assertSame([$due->id], Backup::pluck('source_id')->all());
        $this->assertSame(Backup::TRIGGER_SCHEDULE, Backup::first()->trigger);
        Queue::assertPushed(RunBackup::class, 1);

        // повторный вызов в ту же минуту и пока бекап в очереди — не дублирует
        $this->artisan('backups:dispatch-due');
        Backup::query()->update(['status' => Backup::SUCCESS]);
        $this->artisan('backups:dispatch-due');
        $this->assertSame(1, Backup::count());
    }

    public function test_databases_with_enabled_backup_are_scheduled(): void
    {
        $db = DatabaseConnection::factory()->create(['backup_enabled' => true, 'schedule' => '*/15 * * * *']);
        DatabaseConnection::factory()->create(['backup_enabled' => false, 'schedule' => '*/15 * * * *']);

        $this->travelTo(now()->setTimezone('Europe/Moscow')->setTime(10, 30));
        $this->artisan('backups:dispatch-due');
        $this->travelTo(now()->setTimezone('Europe/Moscow')->setTime(10, 31));
        $this->artisan('backups:dispatch-due');

        $this->assertSame([$db->id], Backup::pluck('source_id')->all());
        $this->assertSame('database', Backup::first()->source_type);
    }

    public function test_command_is_scheduled_every_minute(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('backups:dispatch-due');
    }
}
