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
        // 'TARDATA' — не gzip: объём до сжатия не считается
        $this->assertSame([['name' => 'files.tar.gz', 'size' => 7, 'sha256' => hash('sha256', 'TARDATA')]], $backup->files);
        $this->assertSame(7, $backup->size);
        Storage::disk('backups')->assertExists($backup->path.'/files.tar.gz');
        $this->assertSame('TARDATA', Storage::disk('backups')->get($backup->path.'/files.tar.gz'));
        $this->assertStringStartsWith("servers/{$server->id}/", $backup->path);

        $this->assertSame(ServerBackupRunner::HOST_INFO_COMMAND, $fake->calls[0]['command']);
        $this->assertSame('echo pre', $fake->calls[1]['command']);
        $this->assertStringContainsString("then exec tar -czf - --ignore-failed-read --totals -C / --exclude='*.log' --exclude='var/www/cache' -- 'var/www' 'etc/nginx'; else exec tar -czf - -C / --exclude", $fake->calls[2]['command']);

        $this->assertEquals(['type' => 'server', 'name' => $server->name, 'address' => "{$server->username}@{$server->host}:{$server->port}", 'paths' => ['/var/www', '/etc/nginx'], 'excludes' => ['*.log', '/var/www/cache']], $backup->meta['source']);
        $this->assertEquals(['command' => 'echo pre', 'exit_code' => 0, 'output' => 'TARDATA', 'continued_on_error' => false], array_diff_key($backup->meta['pre_command'], ['duration_ms' => 1]));
        $this->assertArrayHasKey('archive', $backup->meta);

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
        $this->assertCount(2, $fake->calls); // сведения о сервере и команда — tar не запускался

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
        $fake = $this->fake(['stdout' => 'DUMP'], [
            'information_schema' => ['exit_code' => 0, 'stdout' => "shop\t1000\nblog\t2000\nmysql\t10\n", 'stderr' => ''],
            'VERSION()' => ['exit_code' => 0, 'stdout' => "8.4.2\n", 'stderr' => ''],
        ]);

        $id = $this->postJson("/api/v1/databases/{$db->id}/backups")->assertStatus(202)->json('data.id');

        $backup = Backup::find($id);
        $this->assertSame(Backup::SUCCESS, $backup->status);
        $this->assertSame(['shop.sql.gz', 'blog.sql.gz'], array_column($backup->files, 'name'));
        $this->assertStringStartsWith("databases/{$db->id}/", $backup->path);

        $this->assertSame('8.4.2', $backup->meta['source']['version']);
        $this->assertSame('single_transaction', $backup->meta['source']['options']['lock_mode']);
        $this->assertSame(['shop.logs'], $backup->meta['source']['exclude_tables']);
        $this->assertSame([['shop', 1000, 'shop.sql.gz', 4, 'success'], ['blog', 2000, 'blog.sql.gz', 4, 'success']],
            array_map(fn ($d) => [$d['name'], $d['size'], $d['file'], $d['dump_size'], $d['status']], $backup->meta['databases']));

        $cmd = $fake->calls[2]['command'];
        $this->assertStringContainsString("--ignore-table='shop.logs' --ignore-table='shop.sessions' --databases 'shop'", $cmd);
        $this->assertStringContainsString("--no-data --skip-lock-tables 'shop' 'sessions'", $cmd);
        $this->assertStringContainsString('--single-transaction', $cmd);
        $this->assertStringContainsString('| gzip -c', $cmd);
        $this->assertStringNotContainsString('secret', $cmd);
        $this->assertSame("secret\n", $fake->calls[2]['stdin']);
        $this->assertStringNotContainsString('--ignore-table', $fake->calls[3]['command']);
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

    public function test_host_info_and_tar_totals_are_parsed(): void
    {
        $this->assertSame(
            ['hostname' => 'web1', 'os' => 'CentOS Linux 7 (Core)', 'kernel' => 'Linux 3.10.0', 'tools' => ['tar' => 'tar (GNU tar) 1.26']],
            ServerBackupRunner::parseHostInfo("hostname=web1\nkernel=Linux 3.10.0\nos=CentOS Linux 7 (Core)\ntar=tar (GNU tar) 1.26\n"),
        );

        $server = $this->server();
        $this->fake(['exit_code' => 0, 'stdout' => 'TAR', 'stderr' => "Total bytes written: 10240 (10KiB, 5MiB/s)\n"]);
        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id');
        $meta = Backup::find($id)->meta;
        $this->assertSame(10240, $meta['archive']['uncompressed_size']);
        $this->assertNull($meta['archive']['warnings']);
        $this->getJson("/api/v1/backups/{$id}")->assertJsonPath('data.meta.archive.uncompressed_size', 10240);
    }

    public function test_uncompressed_size_is_counted_from_gzip_stream(): void
    {
        $server = $this->server();
        $raw = str_repeat('a', 5_000_000);
        $this->fake(['exit_code' => 0, 'stdout' => gzencode($raw), 'stderr' => '']);

        $id = $this->postJson("/api/v1/servers/{$server->id}/backups")->json('data.id');
        $backup = Backup::find($id);
        $this->assertSame(5_000_000, $backup->files[0]['uncompressed_size']);
        $this->assertSame(5_000_000, $backup->meta['archive']['uncompressed_size']);
    }
}
