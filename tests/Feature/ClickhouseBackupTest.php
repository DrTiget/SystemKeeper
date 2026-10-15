<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Models\User;
use App\Services\Database\DatabaseClient;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class ClickhouseBackupTest extends TestCase
{
    use RefreshDatabase;

    private FakeSshConnector $ssh;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        Sanctum::actingAs(User::factory()->create());
        $this->ssh = new FakeSshConnector(['exit_code' => 0, 'stdout' => 'NATIVEDATA', 'stderr' => '']);
        $this->app->instance(SshConnectorInterface::class, $this->ssh);
    }

    private function ok(string $stdout): array
    {
        return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => ''];
    }

    private function connection(array $attrs = []): DatabaseConnection
    {
        return DatabaseConnection::factory()->create([
            'engine' => 'clickhouse', 'via' => 'ssh', 'server_id' => Server::factory()->create()->id, 'port' => 9000,
            'username' => 'bk', 'password' => 'p@ss', 'databases' => ['shop'], ...$attrs,
        ]);
    }

    public function test_create_uses_native_port_by_default(): void
    {
        $server = Server::factory()->create();

        $this->postJson('/api/v1/databases', [
            'name' => 'CH', 'engine' => 'clickhouse', 'via' => 'ssh', 'server_id' => $server->id, 'host' => '127.0.0.1', 'username' => 'default', 'schedule' => '0 3 * * *',
        ])->assertCreated()->assertJsonPath('data.port', 9000)->assertJsonPath('data.backup.options', ['compress' => true]);
    }

    public function test_lists_databases_and_tables_with_password_in_env(): void
    {
        $c = $this->connection();
        $this->ssh->results = [
            'system.databases' => $this->ok("default\t0\nshop\t12901\nsystem\t500\n"),
            'system.tables' => $this->ok("orders\t1000\t12497\nbig_orders\t\\N\t\\N\n"),
        ];
        $client = app(DatabaseClient::class);

        $this->assertSame([
            ['name' => 'default', 'size' => 0, 'system' => false],
            ['name' => 'shop', 'size' => 12901, 'system' => false],
            ['name' => 'system', 'size' => 500, 'system' => true],
        ], $client->databases($c));
        $this->assertSame([['name' => 'orders', 'rows' => 1000, 'size' => 12497], ['name' => 'big_orders', 'rows' => null, 'size' => null]], $client->tables($c, 'shop'));

        $cmd = $this->ssh->calls[0]['command'];
        $this->assertStringContainsString('CLICKHOUSE_PASSWORD="$SK_DB_PASSWORD"', $cmd);
        $this->assertStringContainsString("--host '127.0.0.1' --port 9000 --user 'bk'", $cmd);
        $this->assertStringNotContainsString('p@ss', $cmd);
        $this->assertSame("p@ss\n", $this->ssh->calls[0]['stdin']);
    }

    public function test_backup_writes_schema_table_data_and_views(): void
    {
        $c = $this->connection(['exclude_tables' => ['shop.tmp'], 'schema_only_tables' => ['shop.log_t']]);
        $this->ssh->results = [
            'SHOW CREATE DATABASE' => $this->ok("CREATE DATABASE shop\\nENGINE = Atomic\n"),
            'create_table_query' => $this->ok(implode("\n", [
                "daily_mv\tMaterializedView\tCREATE MATERIALIZED VIEW shop.daily_mv TO shop.daily AS SELECT 1",
                "log_t\tLog\tCREATE TABLE shop.log_t (`x` String) ENGINE = Log",
                "orders\tMergeTree\tCREATE TABLE shop.orders (`id` UInt64, `note` String)\\nENGINE = MergeTree ORDER BY id",
                "remote\tDistributed\tCREATE TABLE shop.remote AS shop.orders ENGINE = Distributed(c, shop, orders)",
                "tmp\tMergeTree\tCREATE TABLE shop.tmp (`x` UInt8) ENGINE = MergeTree ORDER BY x",
            ])."\n"),
            'system.databases' => $this->ok("shop\t12901\n"),
            'version()' => $this->ok("24.8.14.39\n"),
        ];

        $id = $this->postJson("/api/v1/databases/{$c->id}/backups")->assertStatus(202)->json('data.id');
        $backup = Backup::find($id);

        $this->assertSame(Backup::SUCCESS, $backup->status, (string) $backup->error);
        $this->assertSame(['shop.schema.sql', 'shop.orders.native.gz', 'shop.views.sql'], array_column($backup->files, 'name'));
        $this->assertSame('24.8.14.39', $backup->meta['source']['version']);
        $this->assertSame(4, $backup->meta['databases'][0]['tables']);

        $disk = Storage::disk('backups');
        $schema = $disk->get($backup->path.'/shop.schema.sql');
        $this->assertStringStartsWith("CREATE DATABASE IF NOT EXISTS shop\nENGINE = Atomic;", $schema);
        $this->assertStringContainsString("CREATE TABLE shop.orders (`id` UInt64, `note` String)\nENGINE = MergeTree ORDER BY id;", $schema);
        $this->assertStringContainsString('CREATE TABLE shop.log_t', $schema);
        $this->assertStringNotContainsString('shop.tmp', $schema);
        $this->assertStringNotContainsString('MATERIALIZED VIEW', $schema);
        $this->assertStringContainsString('CREATE MATERIALIZED VIEW shop.daily_mv', $disk->get($backup->path.'/shop.views.sql'));
        $this->assertSame('NATIVEDATA', $disk->get($backup->path.'/shop.orders.native.gz'));

        // данные — только у таблиц, которые их хранят, и не у «только структура»
        $dumps = array_values(array_filter(array_column($this->ssh->calls, 'command'), fn ($cmd) => str_contains($cmd, 'FORMAT Native')));
        $this->assertCount(1, $dumps);
        $this->assertStringContainsString('SELECT * FROM `shop`.`orders` FORMAT Native', $dumps[0]);
        $this->assertStringContainsString('| gzip -c', $dumps[0]);

        $info = json_decode($disk->get($backup->path.'/backup.json'), true);
        $this->assertContains("shop.orders.native.gz: gunzip -c shop.orders.native.gz | clickhouse-client --query 'INSERT INTO shop.orders FORMAT Native'", $info['restore']);
    }

    public function test_table_dump_error_fails_database(): void
    {
        $c = $this->connection();
        $this->ssh->results = [
            'SHOW CREATE DATABASE' => $this->ok("CREATE DATABASE shop\n"),
            'create_table_query' => $this->ok("orders\tMergeTree\tCREATE TABLE shop.orders (`id` UInt64) ENGINE = MergeTree ORDER BY id\n"),
            'FORMAT Native' => ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Code: 516. DB::Exception: bk: Authentication failed (AUTHENTICATION_FAILED)'],
            'system.databases' => $this->ok("shop\t1\n"),
        ];

        $id = $this->postJson("/api/v1/databases/{$c->id}/backups")->json('data.id');
        $backup = Backup::find($id);

        $this->assertSame(Backup::FAILED, $backup->status);
        $this->assertSame('Не удалось сделать дамп: shop.', $backup->error);
        $this->assertSame('orders: Неверный пользователь или пароль БД.', $backup->meta['databases'][0]['error']);
    }

    public function test_user_provisioning_is_not_offered(): void
    {
        $c = $this->connection();

        $this->postJson("/api/v1/databases/{$c->id}/provision-user", ['username' => 'sk', 'admin' => ['mode' => 'local']])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Автонастройка пользователя для ClickHouse пока не поддерживается — создайте пользователя с правом SELECT и SHOW вручную.']);
    }
}
