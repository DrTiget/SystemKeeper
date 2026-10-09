<?php

namespace Tests\Unit;

use App\Models\BackupJob;
use App\Services\Backup\BackupCommandBuilder;
use Tests\TestCase;

class BackupCommandBuilderTest extends TestCase
{
    private function job(array $attrs): BackupJob
    {
        return (new BackupJob)->forceFill($attrs + ['compression_level' => 3]);
    }

    public function test_files_pipeline_streams_tar_into_zstd(): void
    {
        $command = (new BackupCommandBuilder)->build($this->job([
            'type' => 'files', 'paths' => ['/var/www', '/etc/my app'], 'excludes' => ['*.log', '/var/www/cache'],
        ]));

        $this->assertStringStartsWith("bash -o pipefail -c '", $command->command);
        $this->assertStringContainsString('tar -C / --warning=no-file-changed', $command->command);
        $this->assertStringContainsString('-cf', $command->command);
        $this->assertStringContainsString("'\\''--exclude=*.log'\\''", $command->command);
        $this->assertStringContainsString('--exclude=var/www/cache -- var/www', $command->command);
        $this->assertStringContainsString("'\\''etc/my app'\\''", $command->command);
        $this->assertStringContainsString('| zstd -T0 -3 -q -c', $command->command);
        $this->assertSame([], $command->env);
    }

    public function test_compression_level_is_applied(): void
    {
        $command = (new BackupCommandBuilder)->build($this->job(['type' => 'files', 'paths' => ['/a'], 'compression_level' => 19]));

        $this->assertStringContainsString('zstd -T0 -19', $command->command);
    }

    public function test_mysql_password_goes_to_env_not_to_command_line(): void
    {
        $command = (new BackupCommandBuilder)->build($this->job([
            'type' => 'mysql', 'db_host' => '10.0.0.2', 'db_port' => 3307, 'db_name' => 'shop', 'db_user' => 'dumper', 'db_password' => 'p@ss w0rd',
        ]));

        $this->assertStringContainsString('mysqldump', $command->command);
        $this->assertStringContainsString('--host=10.0.0.2', $command->command);
        $this->assertStringContainsString('--port=3307', $command->command);
        $this->assertStringContainsString('--user=dumper', $command->command);
        $this->assertStringContainsString('shop', $command->command);
        $this->assertStringNotContainsString('p@ss', $command->command);
        $this->assertSame(['MYSQL_PWD' => 'p@ss w0rd'], $command->env);
        $this->assertSame(['p@ss w0rd'], $command->secrets);
        $this->assertStringStartsWith("env MYSQL_PWD='p@ss w0rd' bash -o pipefail", $command->forRemote());
    }

    public function test_mysql_without_database_dumps_all(): void
    {
        $command = (new BackupCommandBuilder)->build($this->job(['type' => 'mysql']));

        $this->assertStringContainsString('--all-databases', $command->command);
        $this->assertStringNotContainsString('--host', $command->command);
        $this->assertSame($command->command, $command->forRemote());
    }

    public function test_postgres_uses_pg_dump_or_pg_dumpall(): void
    {
        $one = (new BackupCommandBuilder)->build($this->job(['type' => 'postgres', 'db_name' => 'app', 'db_user' => 'u', 'db_password' => 'x']));
        $all = (new BackupCommandBuilder)->build($this->job(['type' => 'postgres']));

        $this->assertStringContainsString('pg_dump --no-password', $one->command);
        $this->assertStringContainsString('--dbname=app', $one->command);
        $this->assertSame(['PGPASSWORD' => 'x'], $one->env);
        $this->assertStringContainsString('pg_dumpall', $all->command);
    }

    public function test_extension(): void
    {
        $builder = new BackupCommandBuilder;

        $this->assertSame('tar.zst', $builder->extension($this->job(['type' => 'files'])));
        $this->assertSame('sql.zst', $builder->extension($this->job(['type' => 'mysql'])));
    }

    public function test_mysql_skips_tablespaces(): void
    {
        $command = (new BackupCommandBuilder)->build($this->job(['type' => 'mysql', 'db_name' => 'shop']));

        $this->assertStringContainsString('--no-tablespaces', $command->command);
    }

    public function test_mysql_direct_without_ssl_verify_skips_certificate_check(): void
    {
        $builder = new BackupCommandBuilder;

        $direct = $builder->build($this->job(['type' => 'mysql', 'db_mode' => 'direct', 'db_host' => 'db', 'db_ssl_verify' => false]));
        $verified = $builder->build($this->job(['type' => 'mysql', 'db_mode' => 'direct', 'db_host' => 'db', 'db_ssl_verify' => true]));
        $ssh = $builder->build($this->job(['type' => 'mysql', 'db_mode' => 'ssh', 'db_ssl_verify' => false]));

        $this->assertStringContainsString('--skip-ssl-verify-server-cert', $direct->command);
        $this->assertStringNotContainsString('--skip-ssl-verify-server-cert', $verified->command);
        $this->assertStringNotContainsString('--skip-ssl-verify-server-cert', $ssh->command);
    }
}
