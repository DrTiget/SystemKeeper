<?php

namespace Tests\Feature;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Backup;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Models\User;
use App\Services\Backup\DatabaseBackupRunner;
use App\Services\Backup\ServerBackupRunner;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class BackupRunTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function fake(array $result = [], array $results = []): FakeSshConnector
    {
        $fake = new FakeSshConnector([...['exit_code' => 0, 'stdout' => '', 'stderr' => ''], ...$result]);
        $fake->results = $results;
        $this->app->instance(SshConnectorInterface::class, $fake);

        return $fake;
    }

    private function server(array $settings = []): Server
    {
        $server = Server::factory()->create();
        $server->backupSettings()->create([
            'enabled' => false, 'paths' => ['/var/www', '/etc/nginx'], 'excludes' => ['*.log', '/var/www/cache'],
            'schedule' => '0 3 * * *', 'pre_command' => null, 'pre_command_timeout' => 60, 'pre_command_continue_on_error' => false,
            ...$settings,
        ]);

        return $server;
    }

    public function test_server_backup_streams_tar_into_file(): void
    {
        $server = $this->server(['pre_command' => 'echo pre']);
        $fake = $this->fake(['stdout' => 'TARDATA']);

        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")
            ->assertStatus(202)
            ->assertJsonPath('data.trigger', 'manual')
            ->assertJsonPath('data.user.id', $this->user->id)
            ->json('data.id');

        $backup = Backup::findOrFail($id);
        $this->assertSame(Backup::SUCCESS, $backup->status);
        $this->assertSame([['name' => 'files.tar.gz', 'size' => 7]], $backup->files);
        $this->assertSame(7, $backup->size);
        Storage::disk('backups')->assertExists($backup->path.'/files.tar.gz');
        $this->assertSame('TARDATA', Storage::disk('backups')->get($backup->path.'/files.tar.gz'));
        $this->assertStringStartsWith("servers/{$server->id}/", $backup->path);

        $this->assertSame('echo pre', $fake->calls[0]['command']);
        $this->assertStringContainsString("then exec tar -czf - --ignore-failed-read -C / --exclude='*.log' --exclude='var/www/cache' -- 'var/www' 'etc/nginx'; else exec tar -czf - -C / --exclude", $fake->calls[1]['command']);

        $this->getJson("/api/v1/servers/{$server->id}/backups")->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.status', 'success');
        $this->getJson("/api/v1/backups/{$id}/files/files.tar.gz")->assertOk()->assertDownload('files.tar.gz');
    }

    public function test_pre_command_failure_stops_backup_unless_continue(): void
    {
        $server = $this->server(['pre_command' => 'false']);
        $fake = $this->fake(['stdout' => 'TAR'], ['false' => ['exit_code' => 2, 'stdout' => '', 'stderr' => 'boom']]);

        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")->assertStatus(202)->json('data.id');
        $this->getJson("/api/v1/backups/{$id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Команда перед бекапом завершилась с кодом 2: boom');
        $this->assertCount(1, $fake->calls);

        $server->backupSettings->update(['pre_command_continue_on_error' => true]);
        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")->assertStatus(202)->json('data.id');
        $this->assertSame(Backup::SUCCESS, Backup::find($id)->status);
    }

    public function test_tar_warning_is_success_and_error_is_failure(): void
    {
        $server = $this->server();
        $this->fake(['exit_code' => 1, 'stdout' => 'TAR', 'stderr' => 'file changed as we read it']);
        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id');
        $this->assertSame(Backup::SUCCESS, Backup::find($id)->status);

        $this->fake(['exit_code' => 1, 'stdout' => '', 'stderr' => "tar: unrecognized option\nBusyBox v1.37.0"]);
        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id');
        $this->assertSame(Backup::FAILED, Backup::find($id)->status);

        $this->fake(['exit_code' => 2, 'stderr' => 'Cannot open']);
        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id');
        $this->assertSame(Backup::FAILED, Backup::find($id)->status);
        $this->assertStringContainsString('tar завершился с кодом 2', Backup::find($id)->error);
    }

    public function test_ssh_error_marks_backup_failed(): void
    {
        $server = $this->server();
        $fake = $this->fake();
        $fake->throw = new SshConnectionException('Не удалось подключиться');

        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id');
        $this->assertSame('Не удалось подключиться', Backup::find($id)->error);
    }

    public function test_server_without_paths_is_rejected(): void
    {
        $server = Server::factory()->create();
        $this->postJson("/api/v1/servers/{$server->id}/backups")->assertStatus(422)->assertJsonValidationErrors('paths');
    }

    public function test_second_run_while_active_is_conflict(): void
    {
        Queue::fake();
        $server = $this->server();

        $this->postJson("/api/v1/servers/{$server->id}/backups")->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->postJson("/api/v1/servers/{$server->id}/backups")->assertStatus(409);
        $id = Backup::first()->id;
        $this->deleteJson("/api/v1/backups/{$id}")->assertStatus(409);
    }

    public function test_database_backup_dumps_each_database(): void
    {
        $db = DatabaseConnection::factory()->create([
            'engine' => 'mysql', 'via' => 'ssh', 'server_id' => Server::factory()->create()->id, 'username' => 'bk', 'password' => 'secret',
            'databases' => ['shop', 'blog'], 'exclude_tables' => ['shop.logs'], 'schema_only_tables' => ['shop.sessions'],
        ]);
        $fake = $this->fake(['stdout' => 'DUMP']);

        $id = $this->postJson("/api/v1/databases/{$db->id}/backups")->assertStatus(202)->json('data.id');

        $backup = Backup::find($id);
        $this->assertSame(Backup::SUCCESS, $backup->status);
        $this->assertSame(['shop.sql.gz', 'blog.sql.gz'], array_column($backup->files, 'name'));
        $this->assertStringStartsWith("databases/{$db->id}/", $backup->path);

        $cmd = $fake->calls[0]['command'];
        $this->assertStringContainsString("--ignore-table='shop.logs' --ignore-table='shop.sessions' --databases 'shop'", $cmd);
        $this->assertStringContainsString("--no-data --skip-lock-tables 'shop' 'sessions'", $cmd);
        $this->assertStringContainsString('--single-transaction', $cmd);
        $this->assertStringContainsString('| gzip -c', $cmd);
        $this->assertStringNotContainsString('secret', $cmd);
        $this->assertSame("secret\n", $fake->calls[0]['stdin']);
        $this->assertStringNotContainsString('--ignore-table', $fake->calls[1]['command']);
    }

    public function test_database_dump_failure_lists_failed_databases(): void
    {
        $db = DatabaseConnection::factory()->create([
            'engine' => 'postgres', 'via' => 'ssh', 'server_id' => Server::factory()->create()->id, 'databases' => ['app'],
        ]);
        $this->fake(['exit_code' => 1, 'stderr' => 'pg_dump: error: connection failed']);

        $id = $this->postJson("/api/v1/databases/{$db->id}/backups")->json('data.id');
        $backup = Backup::find($id);
        $this->assertSame(Backup::FAILED, $backup->status);
        $this->assertSame('Не удалось сделать дамп: app.', $backup->error);
    }

    public function test_postgres_command_options(): void
    {
        $db = DatabaseConnection::factory()->make([
            'engine' => 'postgres', 'via' => 'direct', 'host' => 'db', 'port' => 5432, 'username' => 'u',
            'exclude_tables' => ['app.public.logs'], 'schema_only_tables' => ['app.public.cache', 'other.public.x'],
            'options' => ['format' => 'custom', 'no_owner' => true, 'compress' => true],
        ]);

        [$cmd, $ext] = DatabaseBackupRunner::postgresCommand($db, 'app', $db->resolvedOptions());
        $this->assertSame('.dump', $ext);
        $this->assertStringContainsString("pg_dump -h 'db' -p 5432 -U 'u' -d 'app' -Fc -O -T 'public.logs' --exclude-table-data='public.cache'", $cmd);
        $this->assertStringNotContainsString('other', $cmd);
    }

    public function test_deleting_backup_and_source_removes_files(): void
    {
        $server = $this->server();
        $this->fake(['stdout' => 'X']);
        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id');
        $path = Backup::find($id)->path;

        $this->deleteJson("/api/v1/backups/{$id}")->assertNoContent();
        Storage::disk('backups')->assertMissing($path);

        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id');
        $path = Backup::find($id)->path;
        $this->deleteJson("/api/v1/servers/{$server->id}")->assertNoContent();
        $this->assertDatabaseCount('backups', 0);
        Storage::disk('backups')->assertMissing($path);
    }

    public function test_tar_command_root_path(): void
    {
        $this->assertStringEndsWith("else exec tar -czf - -C / -- '.'; fi", ServerBackupRunner::tarCommand(['/'], []));
    }
}
