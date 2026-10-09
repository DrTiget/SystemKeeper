<?php

namespace Tests\Feature;

use App\Events\BackupRestoreFailed;
use App\Jobs\RunBackup;
use App\Jobs\VerifyBackupRestore;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\Server;
use App\Models\User;
use App\Services\Backup\LocalCommandRunner;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeLocalCommandRunner;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class RestoreVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        config(['backup.restore.mysql' => ['host' => 'mysql', 'port' => 3306, 'username' => 'sk', 'password' => 'restore-secret', 'ssl_verify' => false]]);
    }

    /** Бекап БД с файлом на диске. */
    private function backup(array $job = [], string $kind = 'mysql'): Backup
    {
        $factory = $kind === 'postgres' ? BackupJob::factory()->postgres() : BackupJob::factory()->mysql();
        $job = $factory->create($job + ['verify_restore' => true]);
        Storage::disk('backups')->put("{$job->server_id}/{$job->id}/dump.sql.zst", 'zstd-bytes');

        return Backup::factory()->success("{$job->server_id}/{$job->id}/dump.sql.zst")->create(['backup_job_id' => $job->id]);
    }

    /**
     * Фейковый раннер, отвечающий как здоровый сервер: 3 таблицы, запрос вернул 42.
     *
     * @param  array<string, array{chunks?: list<string>, exit_code?: int, stderr?: string}>  $overrides  подстрока команды => ответ
     */
    private function runner(array $overrides = [], int $tables = 3): FakeLocalCommandRunner
    {
        $runner = new FakeLocalCommandRunner(handler: function (string $command) use ($overrides, $tables) {
            foreach ($overrides as $needle => $answer) {
                if (str_contains($command, $needle)) {
                    return $answer;
                }
            }
            if (str_contains($command, 'information_schema.tables')) {
                return ['chunks' => ["{$tables}\n"]];
            }
            if (str_contains($command, 'COUNT(*) FROM users') || str_contains($command, 'count(*) FROM users')) {
                return ['chunks' => ["42\n"]];
            }

            return null;
        });
        $this->app->instance(LocalCommandRunner::class, $runner);

        return $runner;
    }

    private function run_(Backup $backup): Backup
    {
        VerifyBackupRestore::dispatchSync($backup->forceFill(['restore_status' => Backup::RESTORE_PENDING]));

        return $backup->refresh();
    }

    public function test_mysql_restore_runs_create_restore_count_and_drop_in_order(): void
    {
        Event::fake([BackupRestoreFailed::class]);
        $runner = $this->runner();
        $backup = $this->backup();

        $this->run_($backup);

        $backup->refresh();
        $db = "sk_restore_{$backup->id}";
        $this->assertSame('ok', $backup->restore_status);
        $this->assertNotNull($backup->restore_checked_at);
        $this->assertStringContainsString('Таблиц после восстановления: 3', $backup->restore_log);
        $this->assertStringContainsString("База {$db} удалена", $backup->restore_log);
        Event::assertNotDispatched(BackupRestoreFailed::class);

        $commands = array_column($runner->calls, 'command');
        $this->assertCount(4, $commands);
        $this->assertStringContainsString("DROP DATABASE IF EXISTS `{$db}`; CREATE DATABASE `{$db}`", $commands[0]);
        $this->assertStringContainsString('zstd -dc', $commands[1]);
        $this->assertStringContainsString($backup->file_path, $commands[1]);
        $this->assertStringContainsString("--database={$db}", $commands[1]);
        $this->assertStringContainsString('/^(USE |CREATE DATABASE )/Id', $commands[1]);
        $this->assertStringNotContainsString('--one-database', $commands[1]);
        $this->assertStringContainsString('pipefail', $commands[1]);
        $this->assertStringContainsString('information_schema.tables', $commands[2]);
        $this->assertStringContainsString($db, $commands[2]);
        $this->assertStringContainsString("DROP DATABASE IF EXISTS `{$db}`", $commands[3]);
        $this->assertStringNotContainsString('CREATE', $commands[3]);
    }

    public function test_password_goes_to_env_not_to_command_line(): void
    {
        $runner = $this->runner();

        $this->run_($this->backup());

        foreach ($runner->calls as $call) {
            $this->assertSame(['MYSQL_PWD' => 'restore-secret'], $call['env']);
            $this->assertStringNotContainsString('restore-secret', $call['command']);
            $this->assertStringContainsString('--host=mysql', $call['command']);
            $this->assertStringContainsString('--skip-ssl-verify-server-cert', $call['command']);
        }
    }

    public function test_restore_error_marks_failed_fires_event_and_still_drops_database(): void
    {
        Event::fake([BackupRestoreFailed::class]);
        $runner = $this->runner(['zstd -dc' => ['exit_code' => 1, 'stderr' => "ERROR 1064 (42000) at line 12: You have an error in your SQL syntax; password restore-secret\n"]]);
        $backup = $this->backup();

        $this->run_($backup);

        $backup->refresh();
        $this->assertSame('failed', $backup->restore_status);
        $this->assertStringContainsString('ERROR 1064', $backup->restore_log);
        $this->assertStringNotContainsString('restore-secret', $backup->restore_log);
        $this->assertStringContainsString('удалена', $backup->restore_log);
        $this->assertStringContainsString('DROP DATABASE', last($runner->calls)['command']);
        Event::assertDispatched(BackupRestoreFailed::class, fn ($e) => $e->backup->is($backup));
        $this->assertSame('backup.restore_failed', BackupRestoreFailed::NAME);
    }

    public function test_zero_tables_fails(): void
    {
        $this->runner(tables: 0);
        $backup = $this->backup();

        $this->run_($backup);

        $this->assertSame('failed', $backup->refresh()->restore_status);
        $this->assertStringContainsString('нет таблиц', $backup->restore_log);
    }

    public function test_verify_query_with_minimum(): void
    {
        $this->runner();
        $ok = $this->backup(['verify_query' => 'SELECT COUNT(*) FROM users', 'verify_query_min' => 40]);
        $low = $this->backup(['verify_query' => 'SELECT COUNT(*) FROM users', 'verify_query_min' => 100]);
        $noMin = $this->backup(['verify_query' => 'SELECT COUNT(*) FROM users', 'verify_query_min' => null]);

        $this->assertSame('ok', $this->run_($ok)->restore_status);
        $this->assertStringContainsString('вернул: 42', $ok->restore_log);

        $this->assertSame('failed', $this->run_($low)->restore_status);
        $this->assertStringContainsString('ожидалось не меньше 100', $low->restore_log);

        $this->assertSame('ok', $this->run_($noMin)->restore_status);
    }

    public function test_verify_query_error_fails(): void
    {
        $this->runner(['COUNT(*) FROM users' => ['exit_code' => 1, 'stderr' => "ERROR 1146 (42S02): Table 'users' doesn't exist"]]);
        $backup = $this->backup(['verify_query' => 'SELECT COUNT(*) FROM users']);

        $this->assertSame('failed', $this->run_($backup)->restore_status);
        $this->assertStringContainsString("doesn't exist", $backup->restore_log);
    }

    public function test_cleanup_failure_is_logged_but_does_not_fail_a_successful_check(): void
    {
        $this->runner(['DROP DATABASE IF EXISTS `sk_restore_' => ['exit_code' => 0]]);
        $runner = new FakeLocalCommandRunner(handler: function (string $c) {
            if (str_contains($c, 'information_schema')) {
                return ['chunks' => ["2\n"]];
            }
            if (str_starts_with($c, 'mysql') && str_contains($c, '--execute') && ! str_contains($c, 'CREATE') && str_contains($c, 'DROP')) {
                return ['exit_code' => 1, 'stderr' => 'locked'];
            }

            return null;
        });
        $this->app->instance(LocalCommandRunner::class, $runner);
        $backup = $this->backup();

        $this->run_($backup);

        $this->assertSame('ok', $backup->refresh()->restore_status);
        $this->assertStringContainsString('Не удалось удалить базу', $backup->restore_log);
    }

    public function test_dump_of_all_databases_is_skipped_without_touching_anything(): void
    {
        Event::fake([BackupRestoreFailed::class]);
        $runner = $this->runner();
        $backup = $this->backup(['db_name' => null]);

        $this->run_($backup);

        $this->assertSame('skipped', $backup->refresh()->restore_status);
        $this->assertStringContainsString('дамп всех баз', $backup->restore_log);
        $this->assertSame([], $runner->calls);
        Event::assertNotDispatched(BackupRestoreFailed::class);
    }

    public function test_postgres_without_restore_target_is_skipped(): void
    {
        $runner = $this->runner();
        $backup = $this->backup(kind: 'postgres');

        $this->run_($backup);

        $this->assertSame('skipped', $backup->refresh()->restore_status);
        $this->assertStringContainsString('restore_host', $backup->restore_log);
        $this->assertSame([], $runner->calls);
    }

    public function test_postgres_restore_with_target(): void
    {
        $runner = $this->runner();
        $backup = $this->backup(['restore_host' => 'pg-test', 'restore_port' => 5433, 'restore_user' => 'tester', 'restore_password' => 'pg-secret'], 'postgres');

        $this->run_($backup);

        $this->assertSame('ok', $backup->refresh()->restore_status);
        $db = "sk_restore_{$backup->id}";
        $commands = array_column($runner->calls, 'command');
        $this->assertStringContainsString("DROP DATABASE IF EXISTS \"{$db}\" WITH (FORCE)", $commands[0]);
        $this->assertStringContainsString("CREATE DATABASE \"{$db}\"", $commands[1]);
        $this->assertStringContainsString('--dbname=postgres', $commands[0]);
        $this->assertStringContainsString('zstd -dc', $commands[2]);
        $this->assertStringContainsString("--dbname={$db}", $commands[2]);
        $this->assertStringContainsString('--host=pg-test', $commands[2]);
        $this->assertStringContainsString('--port=5433', $commands[2]);
        $this->assertStringContainsString('--username=tester', $commands[2]);
        foreach ($runner->calls as $call) {
            $this->assertSame(['PGPASSWORD' => 'pg-secret'], $call['env']);
            $this->assertStringNotContainsString('pg-secret', $call['command']);
        }
    }

    public function test_postgres_ignores_role_errors_but_fails_on_real_errors(): void
    {
        $harmless = "psql:dump.sql:10: ERROR:  role \"app_owner\" does not exist\n";
        $this->runner(['zstd -dc' => ['stderr' => $harmless]]);
        $ok = $this->backup(['restore_host' => 'pg'], 'postgres');
        $this->assertSame('ok', $this->run_($ok)->restore_status);
        $this->assertStringContainsString('Проигнорировано безвредных ошибок', $ok->restore_log);

        $this->runner(['zstd -dc' => ['stderr' => $harmless."psql:dump.sql:22: ERROR:  syntax error at or near \"BOOM\"\n"]]);
        $bad = $this->backup(['restore_host' => 'pg'], 'postgres');
        $this->assertSame('failed', $this->run_($bad)->restore_status);
        $this->assertStringContainsString('syntax error', $bad->restore_log);
    }

    public function test_missing_file_fails(): void
    {
        Event::fake([BackupRestoreFailed::class]);
        $runner = $this->runner();
        $backup = $this->backup();
        Storage::disk('backups')->delete($backup->file_path);

        $this->run_($backup);

        $this->assertSame('failed', $backup->refresh()->restore_status);
        $this->assertStringContainsString('Файл бекапа не найден', $backup->restore_log);
        $this->assertSame([], $runner->calls);
        Event::assertDispatched(BackupRestoreFailed::class);
    }

    public function test_failed_hook_closes_hanging_check_and_fires_event(): void
    {
        Event::fake([BackupRestoreFailed::class]);
        $backup = $this->backup();
        $backup->forceFill(['restore_status' => 'running'])->save();

        (new VerifyBackupRestore($backup))->failed(new \RuntimeException('timeout'));

        $this->assertSame('failed', $backup->refresh()->restore_status);
        $this->assertStringContainsString('timeout', $backup->restore_log);
        Event::assertDispatched(BackupRestoreFailed::class);
    }

    // --- когда проверять ---

    private function afterBackup(BackupJob $job): Backup
    {
        $ssh = new FakeSshConnector;
        $ssh->streamChunks = ['dump'];
        $this->app->instance(SshConnectorInterface::class, $ssh);
        $backup = $job->backups()->create(['status' => Backup::STATUS_PENDING]);
        RunBackup::dispatchSync($backup);

        return $backup->refresh();
    }

    public function test_every_successful_backup_is_checked_by_default(): void
    {
        $this->runner();
        $job = BackupJob::factory()->mysql()->create(['verify_restore' => true]);

        $backup = $this->afterBackup($job);

        // sync-очередь в тестах: проверка выполнилась сразу после бекапа
        $this->assertSame('ok', $backup->restore_status);
    }

    public function test_verification_is_queued_not_run_inline(): void
    {
        Queue::fake([VerifyBackupRestore::class]);
        $job = BackupJob::factory()->mysql()->create(['verify_restore' => true]);

        $backup = $this->afterBackup($job);

        Queue::assertPushed(VerifyBackupRestore::class, fn ($j) => $j->backup->is($backup));
        $this->assertSame('pending', $backup->restore_status);
    }

    public function test_disabled_or_files_jobs_are_not_checked(): void
    {
        Queue::fake([VerifyBackupRestore::class]);

        $this->assertNull($this->afterBackup(BackupJob::factory()->mysql()->create(['verify_restore' => false]))->restore_status);
        $this->assertNull($this->afterBackup(BackupJob::factory()->create(['verify_restore' => true]))->restore_status);
        Queue::assertNothingPushed();
    }

    public function test_every_n_th_backup(): void
    {
        Queue::fake([VerifyBackupRestore::class]);
        $job = BackupJob::factory()->mysql()->create(['verify_restore' => true, 'verify_every_n' => 3]);

        $statuses = collect(range(1, 7))->map(fn () => $this->afterBackup($job)->restore_status)->all();

        $this->assertSame([null, null, 'pending', null, null, 'pending', null], $statuses);
    }

    public function test_every_n_days(): void
    {
        Queue::fake([VerifyBackupRestore::class]);
        Carbon::setTestNow('2026-10-09 12:00:00');
        $job = BackupJob::factory()->mysql()->create(['verify_restore' => true, 'verify_every_days' => 7]);

        $first = $this->afterBackup($job);                      // раньше проверок не было
        $firstStatus = $first->restore_status;
        $first->forceFill(['restore_status' => 'ok', 'restore_checked_at' => now()])->save();
        Carbon::setTestNow(now()->addDays(3));
        $second = $this->afterBackup($job);                     // прошло 3 дня
        Carbon::setTestNow(now()->addDays(4)->addMinute());
        $third = $this->afterBackup($job);                      // прошло больше 7 суток

        $this->assertSame('pending', $firstStatus);
        $this->assertNull($second->restore_status);
        $this->assertSame('pending', $third->restore_status);
        Carbon::setTestNow();
    }

    public function test_n_and_days_combine_with_or(): void
    {
        Queue::fake([VerifyBackupRestore::class]);
        $job = BackupJob::factory()->mysql()->create(['verify_restore' => true, 'verify_every_n' => 2, 'verify_every_days' => 30]);

        $a = $this->afterBackup($job); // первая проверка по дням
        $aStatus = $a->restore_status;
        $a->forceFill(['restore_status' => 'ok', 'restore_checked_at' => now()])->save();
        $b = $this->afterBackup($job); // второй бекап — кратен 2

        $this->assertSame('pending', $aStatus);
        $this->assertSame('pending', $b->restore_status);
    }

    // --- API ---

    public function test_job_api_accepts_and_hides_restore_settings(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();

        $response = $this->postJson('/api/v1/backup-jobs', [
            'name' => 'pg', 'server_id' => $server->id, 'type' => 'postgres', 'db_name' => 'app',
            'verify_restore' => true, 'verify_every_n' => 5, 'verify_every_days' => 7,
            'verify_query' => 'SELECT count(*) FROM users', 'verify_query_min' => 10,
            'restore_host' => 'pg-test', 'restore_port' => 5432, 'restore_user' => 'u', 'restore_password' => 'secret-restore-pw',
        ])->assertCreated()
            ->assertJsonPath('data.verify_restore', true)
            ->assertJsonPath('data.verify_every_n', 5)
            ->assertJsonPath('data.verify_query_min', 10)
            ->assertJsonPath('data.restore_host', 'pg-test')
            ->assertJsonPath('data.has_restore_password', true);

        $this->assertStringNotContainsString('secret-restore-pw', $response->getContent());
        $this->assertSame('secret-restore-pw', BackupJob::first()->restore_password);
        $this->assertNotSame('secret-restore-pw', \DB::table('backup_jobs')->value('restore_password'));
    }

    public function test_job_api_validates_restore_settings(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();
        $base = ['name' => 'db', 'server_id' => $server->id, 'type' => 'mysql', 'db_name' => 'app'];

        $this->postJson('/api/v1/backup-jobs', $base + ['verify_query' => 'DROP TABLE users'])->assertUnprocessable()->assertJsonValidationErrors(['verify_query']);
        $this->postJson('/api/v1/backup-jobs', $base + ['verify_query' => 'SELECT 1; DROP TABLE users'])->assertUnprocessable()->assertJsonValidationErrors(['verify_query']);
        $this->postJson('/api/v1/backup-jobs', $base + ['verify_every_n' => 0, 'verify_every_days' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['verify_every_n', 'verify_every_days']);
        $this->postJson('/api/v1/backup-jobs', $base + ['restore_host' => 'bad host;'])->assertUnprocessable()->assertJsonValidationErrors(['restore_host']);
        $this->postJson('/api/v1/backup-jobs', $base + ['verify_query' => 'select count(*) from users;'])->assertCreated();
    }

    public function test_files_job_ignores_restore_settings(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();

        $this->postJson('/api/v1/backup-jobs', ['name' => 'f', 'server_id' => $server->id, 'type' => 'files', 'paths' => ['/etc']])
            ->assertCreated()->assertJsonPath('data.verify_restore', false);
    }

    public function test_backup_api_exposes_restore_result(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $backup = $this->backup();
        $backup->forceFill(['restore_status' => 'failed', 'restore_checked_at' => now(), 'restore_log' => 'ERROR 1064'])->save();

        $list = $this->getJson('/api/v1/backups')->assertOk()->assertJsonPath('data.0.restore_status', 'failed');
        $this->assertArrayNotHasKey('restore_log', $list->json('data.0'));
        $this->getJson("/api/v1/backups/{$backup->id}")->assertOk()
            ->assertJsonPath('data.restore_status', 'failed')
            ->assertJsonPath('data.restore_log', 'ERROR 1064')
            ->assertJsonPath('data.restore_checked_at', fn ($v) => $v !== null);
    }

    public function test_manual_verify_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Queue::fake([VerifyBackupRestore::class]);
        $backup = $this->backup(['verify_restore' => false]);

        $this->postJson("/api/v1/backups/{$backup->id}/verify-restore")
            ->assertAccepted()->assertJsonPath('data.restore_status', 'pending');
        Queue::assertPushed(VerifyBackupRestore::class, 1);

        // повторно, пока идёт предыдущая
        $this->postJson("/api/v1/backups/{$backup->id}/verify-restore")->assertConflict();
        Queue::assertPushed(VerifyBackupRestore::class, 1);
    }

    public function test_manual_verify_rejects_files_failed_and_missing_file(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Queue::fake([VerifyBackupRestore::class]);
        Storage::disk('backups')->put('1/1/a.tar.zst', 'x');
        $files = Backup::factory()->success('1/1/a.tar.zst')->create();
        $failed = Backup::factory()->failed()->create(['backup_job_id' => BackupJob::factory()->mysql()->create()->id]);
        $gone = Backup::factory()->success('9/9/gone.sql.zst')->create(['backup_job_id' => BackupJob::factory()->mysql()->create()->id]);

        $this->postJson("/api/v1/backups/{$files->id}/verify-restore")->assertUnprocessable();
        $this->postJson("/api/v1/backups/{$failed->id}/verify-restore")->assertNotFound();
        $this->postJson("/api/v1/backups/{$gone->id}/verify-restore")->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_verify_endpoint_requires_auth(): void
    {
        $this->postJson('/api/v1/backups/1/verify-restore')->assertUnauthorized();
    }

    public function test_stale_pending_check_can_be_restarted(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Queue::fake([VerifyBackupRestore::class]);
        $backup = $this->backup();
        $backup->forceFill(['restore_status' => 'running'])->saveQuietly();
        $backup->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();

        $this->postJson("/api/v1/backups/{$backup->id}/verify-restore")->assertAccepted();
    }
}
