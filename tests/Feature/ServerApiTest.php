<?php

namespace Tests\Feature;

use App\Exceptions\Ssh\HostFingerprintChangedException;
use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\User;
use App\Services\Ssh\SshConnector;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class ServerApiTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function fake(FakeSshConnector $fake = new FakeSshConnector): FakeSshConnector
    {
        $this->app->instance(SshConnectorInterface::class, $fake);

        return $fake;
    }

    public function test_requires_authentication(): void
    {
        $server = Server::factory()->create();

        $this->getJson('/api/v1/servers')->assertUnauthorized();
        $this->postJson('/api/v1/servers', [])->assertUnauthorized();
        $this->getJson("/api/v1/servers/{$server->id}")->assertUnauthorized();
        $this->putJson("/api/v1/servers/{$server->id}", [])->assertUnauthorized();
        $this->deleteJson("/api/v1/servers/{$server->id}")->assertUnauthorized();
        $this->postJson("/api/v1/servers/{$server->id}/check")->assertUnauthorized();
        $this->postJson("/api/v1/servers/{$server->id}/reset-fingerprint")->assertUnauthorized();
    }

    public function test_creates_server_with_key_and_default_port(): void
    {
        $this->login();
        $key = SshKey::factory()->create();

        $this->postJson('/api/v1/servers', [
            'name' => 'web-1', 'host' => '10.0.0.5', 'username' => 'backup', 'auth_type' => 'key', 'ssh_key_id' => $key->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.port', 22)
            ->assertJsonPath('data.auth_type', 'key')
            ->assertJsonPath('data.ssh_key.name', $key->name)
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.last_check_status', null);
    }

    public function test_creates_server_with_password_and_hides_it(): void
    {
        $this->login();

        $response = $this->postJson('/api/v1/servers', [
            'name' => 'db-1', 'host' => 'db.example.com', 'port' => 2222, 'username' => 'root',
            'auth_type' => 'password', 'password' => 'p@ssw0rd-secret', 'description' => 'БД',
        ])->assertCreated()->assertJsonPath('data.has_password', true)->assertJsonPath('data.port', 2222);

        $this->assertStringNotContainsString('p@ssw0rd-secret', $response->getContent());
        $this->assertStringNotContainsString('p@ssw0rd-secret', $this->getJson('/api/v1/servers')->getContent());
        $this->assertSame('p@ssw0rd-secret', Server::first()->password);
        $this->assertStringNotContainsString('p@ssw0rd-secret', DB::table('servers')->value('password'));
        $this->assertArrayNotHasKey('password', Server::first()->toArray());
    }

    public function test_validation(): void
    {
        $this->login();

        $this->postJson('/api/v1/servers', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'host', 'username', 'auth_type']);
        $this->postJson('/api/v1/servers', ['name' => 'a', 'host' => 'h', 'username' => 'u', 'auth_type' => 'key'])
            ->assertUnprocessable()->assertJsonValidationErrors('ssh_key_id');
        $this->postJson('/api/v1/servers', ['name' => 'a', 'host' => 'h', 'username' => 'u', 'auth_type' => 'key', 'ssh_key_id' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors('ssh_key_id');
        $this->postJson('/api/v1/servers', ['name' => 'a', 'host' => 'h', 'username' => 'u', 'auth_type' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/servers', ['name' => 'a', 'host' => 'h', 'username' => 'u', 'auth_type' => 'password', 'password' => 'x', 'port' => 70000])
            ->assertUnprocessable()->assertJsonValidationErrors('port');
        $this->postJson('/api/v1/servers', ['name' => 'a', 'host' => 'h', 'username' => 'u', 'auth_type' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('auth_type');
    }

    public function test_unneeded_credentials_are_dropped(): void
    {
        $this->login();
        $key = SshKey::factory()->create();

        $this->postJson('/api/v1/servers', [
            'name' => 'a', 'host' => 'h', 'username' => 'u', 'auth_type' => 'key', 'ssh_key_id' => $key->id, 'password' => 'leftover',
        ])->assertCreated()->assertJsonPath('data.has_password', false);
    }

    public function test_index_paginates_and_show(): void
    {
        $this->login();
        $servers = Server::factory()->count(3)->create();

        $this->getJson('/api/v1/servers?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson("/api/v1/servers/{$servers[0]->id}")->assertOk()->assertJsonPath('data.id', $servers[0]->id);
        $this->getJson('/api/v1/servers/999')->assertNotFound();
    }

    public function test_update_partial_and_switch_auth_type(): void
    {
        $this->login();
        $server = Server::factory()->create(['name' => 'old']);

        $this->patchJson("/api/v1/servers/{$server->id}", ['name' => 'new'])->assertOk()->assertJsonPath('data.name', 'new');

        $this->putJson("/api/v1/servers/{$server->id}", ['auth_type' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson("/api/v1/servers/{$server->id}", ['auth_type' => 'password', 'password' => 'pw'])
            ->assertOk()->assertJsonPath('data.has_password', true)->assertJsonPath('data.ssh_key_id', null);

        $key = SshKey::factory()->create();
        $this->putJson("/api/v1/servers/{$server->id}", ['auth_type' => 'key'])
            ->assertUnprocessable()->assertJsonValidationErrors('ssh_key_id');
        $this->putJson("/api/v1/servers/{$server->id}", ['auth_type' => 'key', 'ssh_key_id' => $key->id])
            ->assertOk()->assertJsonPath('data.has_password', false)->assertJsonPath('data.ssh_key_id', $key->id);
    }

    public function test_changing_host_resets_fingerprint(): void
    {
        $this->login();
        $server = Server::factory()->create(['host_fingerprint' => 'SHA256:abc']);

        $this->patchJson("/api/v1/servers/{$server->id}", ['name' => 'x'])->assertJsonPath('data.host_fingerprint', 'SHA256:abc');
        $this->patchJson("/api/v1/servers/{$server->id}", ['host' => 'new.example.com'])->assertJsonPath('data.host_fingerprint', null);
    }

    public function test_destroy(): void
    {
        $this->login();
        $server = Server::factory()->create();

        $this->deleteJson("/api/v1/servers/{$server->id}")->assertNoContent();
        $this->assertDatabaseMissing('servers', ['id' => $server->id]);
        $this->assertDatabaseHas('ssh_keys', ['id' => $server->ssh_key_id]);
    }

    public function test_check_success_stores_result_and_fingerprint(): void
    {
        $this->login();
        $fake = $this->fake(new FakeSshConnector(rememberFingerprint: 'SHA256:host1'));
        $server = Server::factory()->create();

        $this->postJson("/api/v1/servers/{$server->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.output.exit_code', 0)
            ->assertJsonPath('data.output.stdout', "Linux test 6.1\n up 3 days")
            ->assertJsonPath('data.server.last_check_status', 'ok')
            ->assertJsonPath('data.server.host_fingerprint', 'SHA256:host1')
            ->assertJsonStructure(['data' => ['checked_at']]);

        $this->assertSame('uname -a && uptime', $fake->calls[0]['command']);
        $fresh = $server->fresh();
        $this->assertSame('ok', $fresh->last_check_status);
        $this->assertNotNull($fresh->last_checked_at);
        $this->assertNull($fresh->last_check_error);
    }

    public function test_check_failure_is_saved(): void
    {
        $this->login();
        $this->fake(new FakeSshConnector(throw: new SshConnectionException('Connection refused')));
        $server = Server::factory()->create(['last_check_status' => 'ok']);

        $this->postJson("/api/v1/servers/{$server->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Connection refused')
            ->assertJsonPath('data.output', null);

        $fresh = $server->fresh();
        $this->assertSame('failed', $fresh->last_check_status);
        $this->assertSame('Connection refused', $fresh->last_check_error);
        $this->assertNotNull($fresh->last_checked_at);
    }

    public function test_check_nonzero_exit_code_is_failure(): void
    {
        $this->login();
        $this->fake(new FakeSshConnector(['exit_code' => 127, 'stdout' => '', 'stderr' => 'uptime: not found']));
        $server = Server::factory()->create();

        $this->postJson("/api/v1/servers/{$server->id}/check")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.output.exit_code', 127);
        $this->assertStringContainsString('127', $server->fresh()->last_check_error);
    }

    public function test_check_reports_changed_fingerprint_and_reset_allows_new_one(): void
    {
        $this->login();
        $server = Server::factory()->create(['host_fingerprint' => 'SHA256:old']);
        $this->fake(new FakeSshConnector(throw: new HostFingerprintChangedException));

        $this->postJson("/api/v1/servers/{$server->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Отпечаток хоста изменился');
        $this->assertSame('SHA256:old', $server->fresh()->host_fingerprint);

        $this->postJson("/api/v1/servers/{$server->id}/reset-fingerprint")
            ->assertOk()->assertJsonPath('data.host_fingerprint', null);

        $this->fake(new FakeSshConnector(rememberFingerprint: 'SHA256:new'));
        $this->postJson("/api/v1/servers/{$server->id}/check")->assertJsonPath('data.status', 'ok');
        $this->assertSame('SHA256:new', $server->fresh()->host_fingerprint);
    }

    public function test_connector_trust_on_first_use_logic(): void
    {
        $connector = new SshConnector;
        $server = Server::factory()->create();

        $connector->verifyHostFingerprint($server, 'SHA256:first');
        $connector->rememberHostFingerprint($server, 'SHA256:first');
        $this->assertSame('SHA256:first', $server->fresh()->host_fingerprint);

        $connector->verifyHostFingerprint($server->fresh(), 'SHA256:first');
        $connector->rememberHostFingerprint($server->fresh(), 'SHA256:other');
        $this->assertSame('SHA256:first', $server->fresh()->host_fingerprint);

        $this->expectException(HostFingerprintChangedException::class);
        $connector->verifyHostFingerprint($server->fresh(), 'SHA256:other');
    }

    public function test_container_binds_real_connector_by_default(): void
    {
        $this->assertInstanceOf(SshConnector::class, app(SshConnectorInterface::class));
    }
}
