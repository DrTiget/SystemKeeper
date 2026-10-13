<?php

namespace Tests\Feature;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class DatabaseConnectionApiTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        $this->server = Server::factory()->create(['name' => 'web']);
    }

    private function fakeSsh(array $result = [], ?\Throwable $throw = null): FakeSshConnector
    {
        $fake = new FakeSshConnector([...['exit_code' => 0, 'stdout' => '', 'stderr' => ''], ...$result], $throw);
        $this->app->instance(SshConnectorInterface::class, $fake);

        return $fake;
    }

    private function viaSsh(array $attrs = []): DatabaseConnection
    {
        return DatabaseConnection::factory()->create(['via' => 'ssh', 'server_id' => $this->server->id, 'password' => 'db-secret', ...$attrs]);
    }

    public function test_create_mysql_via_server_with_defaults_and_hidden_password(): void
    {
        $response = $this->postJson('/api/v1/databases', [
            'name' => 'shop', 'engine' => 'mysql', 'via' => 'ssh', 'server_id' => $this->server->id,
            'host' => '127.0.0.1', 'username' => 'backup', 'password' => ' p@ss ',
        ])->assertCreated()
            ->assertJsonPath('data.port', 3306)
            ->assertJsonPath('data.server.name', 'web')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.backup.enabled', false)
            ->assertJsonPath('data.backup.schedule', '0 3 * * *')
            ->assertJsonPath('data.backup.databases', [])
            ->assertJsonPath('data.backup.options', ['lock_mode' => 'single_transaction', 'routines' => true, 'events' => true, 'triggers' => true, 'compress' => true])
            ->assertJsonMissingPath('data.password');

        $this->assertStringNotContainsString('p@ss', $response->getContent());
        $this->assertSame(' p@ss ', DatabaseConnection::first()->password);
        $this->assertStringNotContainsString('p@ss', DB::table('database_connections')->value('password'));
    }

    public function test_create_postgres_direct_defaults(): void
    {
        $this->postJson('/api/v1/databases', [
            'name' => 'pg', 'engine' => 'postgres', 'via' => 'direct', 'host' => 'db.example.com', 'username' => 'postgres',
            'server_id' => $this->server->id,
        ])->assertCreated()
            ->assertJsonPath('data.port', 5432)
            ->assertJsonPath('data.server', null)
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.backup.options', ['format' => 'custom', 'no_owner' => false, 'compress' => true]);
    }

    public function test_validation(): void
    {
        $this->postJson('/api/v1/databases', ['engine' => 'oracle', 'via' => 'ssh'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'engine', 'host', 'username', 'server_id']);

        $this->postJson('/api/v1/databases', [
            'name' => 'x', 'engine' => 'mysql', 'via' => 'direct', 'host' => 'h', 'username' => 'u',
            'docker_container' => 'mysql', 'schedule' => 'nope',
            'exclude_tables' => ['no_dot'], 'options' => ['format' => 'custom', 'lock_mode' => 'eternal'],
        ])->assertJsonValidationErrors(['docker_container', 'schedule', 'exclude_tables.0', 'options', 'options.lock_mode']);
    }

    public function test_update_backup_settings_and_keep_password(): void
    {
        $db = $this->viaSsh();

        $this->patchJson("/api/v1/databases/{$db->id}", [
            'backup_enabled' => true,
            'schedule' => '0 2 * * 0',
            'databases' => ['shop', 'crm'],
            'exclude_tables' => ['shop.sessions'],
            'schema_only_tables' => ['shop.logs'],
            'options' => ['lock_mode' => 'lock_tables'],
        ])->assertOk()
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.backup.enabled', true)
            ->assertJsonPath('data.backup.databases', ['shop', 'crm'])
            ->assertJsonPath('data.backup.options.lock_mode', 'lock_tables')
            ->assertJsonPath('data.backup.options.routines', true);

        $this->assertSame('db-secret', $db->fresh()->password);
        $this->assertNotNull($this->getJson("/api/v1/databases/{$db->id}")->json('data.backup.next_run_at'));

        // смена СУБД сбрасывает параметры дампа
        $this->patchJson("/api/v1/databases/{$db->id}", ['engine' => 'postgres'])
            ->assertJsonPath('data.backup.options', ['format' => 'custom', 'no_owner' => false, 'compress' => true]);
    }

    public function test_switch_to_direct_clears_server(): void
    {
        $db = $this->viaSsh(['docker_container' => 'mysql']);

        $this->patchJson("/api/v1/databases/{$db->id}", ['via' => 'direct'])
            ->assertOk()->assertJsonPath('data.server', null)->assertJsonPath('data.docker_container', null);
    }

    public function test_check_runs_client_on_server_with_password_on_stdin(): void
    {
        $db = $this->viaSsh(['docker_container' => 'mysql-1']);
        $fake = $this->fakeSsh(['stdout' => "8.4.2\n"]);

        $this->postJson("/api/v1/databases/{$db->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.version', '8.4.2')
            ->assertJsonPath('data.connection.last_check.server_version', '8.4.2');

        $call = $fake->calls[0];
        $this->assertSame("db-secret\n", $call['stdin']);
        $this->assertStringNotContainsString('db-secret', $call['command']);
        $this->assertStringContainsString("docker exec -i -e MYSQL_PWD 'mysql-1' sh -c 'LC_ALL=C \"$(command -v mysql || command -v mariadb || echo mysql)\" --batch", $call['command']);
        $this->assertStringContainsString('SELECT VERSION()', $call['command']);
    }

    public function test_check_failures_are_explained(): void
    {
        $db = $this->viaSsh();

        $this->fakeSsh(['exit_code' => 1, 'stderr' => "ERROR 1045 (28000): Access denied for user 'root'@'localhost'"]);
        $this->postJson("/api/v1/databases/{$db->id}/check")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Неверный пользователь или пароль БД.');

        $this->fakeSsh(['exit_code' => 127, 'stderr' => 'sh: mysql: not found']);
        $this->postJson("/api/v1/databases/{$db->id}/check")->assertJsonPath('data.error', 'Клиент mysql не найден на сервере.');

        $this->fakeSsh(throw: new SshConnectionException('Авторизация не удалась для пользователя root.'));
        $this->postJson("/api/v1/databases/{$db->id}/check")->assertJsonPath('data.error', 'Авторизация не удалась для пользователя root.');
    }

    public function test_schemas_and_tables(): void
    {
        $db = $this->viaSsh();
        $this->fakeSsh(['stdout' => "information_schema\t0\nshop\t1048576\n"]);

        $this->getJson("/api/v1/databases/{$db->id}/schemas")
            ->assertOk()
            ->assertExactJson(['data' => [
                ['name' => 'information_schema', 'size' => 0, 'system' => true],
                ['name' => 'shop', 'size' => 1048576, 'system' => false],
            ]]);

        $fake = $this->fakeSsh(['stdout' => "orders\t1200\t65536\nsessions\tNULL\t16384\n"]);
        $this->getJson("/api/v1/databases/{$db->id}/tables?database=".urlencode("sh'op"))
            ->assertOk()
            ->assertJsonPath('data.0', ['name' => 'orders', 'rows' => 1200, 'size' => 65536])
            ->assertJsonPath('data.1.rows', null);
        // имя базы экранировано в SQL
        $this->assertStringContainsString("table_schema = '\\''sh\\'\\''op'\\''", $fake->calls[0]['command']);

        $this->fakeSsh(['exit_code' => 1, 'stderr' => 'ERROR 1044: Access denied']);
        $this->getJson("/api/v1/databases/{$db->id}/schemas")->assertStatus(422)->assertJsonPath('message', 'Неверный пользователь или пароль БД.');
    }

    public function test_postgres_queries_use_psql(): void
    {
        $db = $this->viaSsh(['engine' => 'postgres', 'port' => 5432, 'username' => 'postgres']);
        $fake = $this->fakeSsh(['stdout' => "app\t8000000\npostgres\t7000000\n"]);

        $this->getJson("/api/v1/databases/{$db->id}/schemas")->assertJsonPath('data.1.system', true);

        $this->assertStringContainsString('PGPASSWORD="$SK_DB_PASSWORD"', $fake->calls[0]['command']);
        $this->assertStringContainsString("psql -X -q -A -t -v ON_ERROR_STOP=1 -F '\t' -h '127.0.0.1' -p 5432 -U 'postgres' -d 'postgres'", $fake->calls[0]['command']);
    }

    public function test_server_with_databases_cannot_be_deleted(): void
    {
        $db = $this->viaSsh();

        $this->deleteJson("/api/v1/servers/{$this->server->id}")->assertStatus(409);

        $this->deleteJson("/api/v1/databases/{$db->id}")->assertNoContent();
        $this->deleteJson("/api/v1/servers/{$this->server->id}")->assertNoContent();
    }

    public function test_index_filters_by_server(): void
    {
        $this->viaSsh(['name' => 'a']);
        DatabaseConnection::factory()->create(['name' => 'b']);

        $this->getJson('/api/v1/databases')->assertJsonPath('data.*.name', ['a', 'b']);
        $this->getJson("/api/v1/databases?server_id={$this->server->id}")->assertJsonPath('data.*.name', ['a']);
    }
}
