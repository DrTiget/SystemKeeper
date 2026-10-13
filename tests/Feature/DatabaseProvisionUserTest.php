<?php

namespace Tests\Feature;

use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class DatabaseProvisionUserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    private function connection(array $attrs = []): DatabaseConnection
    {
        return DatabaseConnection::factory()->create([
            'via' => 'ssh', 'server_id' => Server::factory()->create()->id, 'username' => 'root', 'password' => null, ...$attrs,
        ]);
    }

    private function fake(array $result): FakeSshConnector
    {
        $fake = new FakeSshConnector([...['exit_code' => 0, 'stdout' => '', 'stderr' => ''], ...$result]);
        $this->app->instance(SshConnectorInterface::class, $fake);

        return $fake;
    }

    public function test_mysql_local_creates_user_and_saves_generated_password(): void
    {
        $db = $this->connection();
        $fake = $this->fake(['stdout' => "8.4.2\n"]);

        $this->postJson("/api/v1/databases/{$db->id}/provision-user", ['admin' => ['mode' => 'local']])
            ->assertOk()
            ->assertJsonPath('data.connection.username', 'systemkeeper_backup')
            ->assertJsonPath('data.connection.has_password', true)
            ->assertJsonPath('data.connection.last_check.status', 'ok')
            ->assertJsonCount(3, 'data.log');

        $password = $db->fresh()->password;
        $this->assertSame(32, strlen($password));
        $this->assertSame('systemkeeper_backup', $db->fresh()->username);

        [$create, $routine, $check] = $fake->calls;
        // SQL и пароль — только в stdin
        $this->assertStringContainsString("CREATE USER IF NOT EXISTS 'systemkeeper_backup'@'127.0.0.1' IDENTIFIED BY '{$password}'", $create['stdin']);
        $this->assertStringContainsString('GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT, PROCESS ON *.*', $create['stdin']);
        $this->assertStringNotContainsString($password, $create['command']);
        $this->assertStringContainsString('sudo -n "$M" -uroot', $create['command']);
        $this->assertStringContainsString('GRANT SHOW_ROUTINE', $routine['stdin']);
        // проверка выполнена уже под новым пользователем
        $this->assertSame($password."\n", $check['stdin']);
        $this->assertStringContainsString("-u 'systemkeeper_backup'", $check['command']);
    }

    public function test_postgres_in_docker_with_custom_username(): void
    {
        $db = $this->connection(['engine' => 'postgres', 'port' => 5432, 'docker_container' => 'pg', 'host' => 'db.internal']);
        $fake = $this->fake(['stdout' => "PostgreSQL 17\n"]);

        $this->postJson("/api/v1/databases/{$db->id}/provision-user", ['username' => 'sk_dump', 'admin' => ['mode' => 'local']])
            ->assertOk()->assertJsonPath('data.connection.username', 'sk_dump');

        $this->assertStringContainsString("docker exec -i 'pg' sh -c", $fake->calls[0]['command']);
        $this->assertStringContainsString('POSTGRES_USER:-postgres', $fake->calls[0]['command']);
        $this->assertStringContainsString('GRANT pg_read_all_data TO "sk_dump"', $fake->calls[0]['stdin']);
        $this->assertCount(2, $fake->calls); // без SHOW_ROUTINE
    }

    public function test_credentials_mode_passes_admin_password_first_on_stdin(): void
    {
        $db = $this->connection(['host' => '10.0.0.5']);
        $fake = $this->fake(['stdout' => "8.0.36\n"]);

        $this->postJson("/api/v1/databases/{$db->id}/provision-user", ['admin' => ['mode' => 'credentials', 'username' => 'admin', 'password' => ' adm pw ']])
            ->assertOk();

        $this->assertStringStartsWith(" adm pw \nCREATE USER IF NOT EXISTS 'systemkeeper_backup'@'%'", $fake->calls[0]['stdin']);
        $this->assertStringContainsString("-u 'admin'", $fake->calls[0]['command']);
        $this->assertStringNotContainsString('adm pw', $fake->calls[0]['command']);
        $this->assertNotSame(' adm pw ', $db->fresh()->password);
    }

    public function test_errors_are_explained_and_nothing_saved(): void
    {
        $db = $this->connection();

        $this->fake(['exit_code' => 1, 'stderr' => "sudo: a password is required\n"]);
        $this->postJson("/api/v1/databases/{$db->id}/provision-user", ['admin' => ['mode' => 'local']])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Нет прав администратора'));

        $this->fake(['exit_code' => 1, 'stderr' => "ERROR 1045 (28000): Access denied for user 'admin'"]);
        $this->postJson("/api/v1/databases/{$db->id}/provision-user", ['admin' => ['mode' => 'credentials', 'username' => 'admin']])
            ->assertStatus(422)->assertJsonPath('message', 'Неверный логин или пароль администратора БД.');

        $this->assertSame('root', $db->fresh()->username);
    }

    public function test_validation_and_direct_connections(): void
    {
        $db = $this->connection();
        $this->postJson("/api/v1/databases/{$db->id}/provision-user", ['username' => 'Bad-Name', 'admin' => ['mode' => 'credentials']])
            ->assertJsonValidationErrors(['username', 'admin.username']);

        $direct = DatabaseConnection::factory()->create();
        $this->postJson("/api/v1/databases/{$direct->id}/provision-user", ['admin' => ['mode' => 'local']])->assertStatus(422);
    }
}
