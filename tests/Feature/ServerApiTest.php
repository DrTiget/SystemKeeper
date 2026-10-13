<?php

namespace Tests\Feature;

use App\Exceptions\Ssh\HostFingerprintChangedException;
use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Server;
use App\Models\User;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use Tests\Support\FakeSshConnector;
use Tests\TestCase;

class ServerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Серверами пользуются все пользователи, не только администраторы
        Sanctum::actingAs(User::factory()->create());
    }

    private function fakeSsh(FakeSshConnector $fake): FakeSshConnector
    {
        $this->app->instance(SshConnectorInterface::class, $fake);

        return $fake;
    }

    /** @var array<string, string> зашифрованные ключи дорого генерировать (bcrypt KDF) — кешируем на весь прогон */
    private static array $keys = [];

    private static function privateKey(?string $passphrase = null): string
    {
        return self::$keys[(string) $passphrase] ??= (function () use ($passphrase) {
            $key = EC::createKey('Ed25519');

            return ($passphrase ? $key->withPassword($passphrase) : $key)->toString('OpenSSH');
        })();
    }

    public function test_requires_auth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/servers')->assertUnauthorized();
    }

    public function test_create_with_password_hides_secret(): void
    {
        $response = $this->postJson('/api/v1/servers', [
            'name' => 'web-1', 'host' => '10.0.0.5', 'username' => 'deploy', 'auth_type' => 'password', 'password' => 's3cret',
        ])->assertCreated()
            ->assertJsonPath('data.port', 22)
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.has_private_key', false)
            ->assertJsonPath('data.last_check.status', null)
            ->assertJsonMissingPath('data.password');

        $this->assertStringNotContainsString('s3cret', $response->getContent());
        $server = Server::find($response->json('data.id'));
        $this->assertSame('s3cret', $server->password);
        // в БД пароль зашифрован
        $this->assertStringNotContainsString('s3cret', DB::table('servers')->value('password'));
    }

    public function test_create_with_key_and_passphrase(): void
    {
        $key = self::privateKey('phrase');

        $response = $this->postJson('/api/v1/servers', [
            'name' => 'db', 'host' => 'db.example.com', 'port' => 2222, 'username' => 'root',
            'auth_type' => 'key', 'private_key' => $key, 'passphrase' => 'phrase',
        ])->assertCreated()
            ->assertJsonPath('data.has_private_key', true)
            ->assertJsonPath('data.has_passphrase', true)
            ->assertJsonPath('data.port', 2222);

        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());
        $this->assertSame($key, Server::find($response->json('data.id'))->private_key);
    }

    public function test_create_validates(): void
    {
        $this->postJson('/api/v1/servers', ['auth_type' => 'telnet', 'port' => 70000, 'host' => 'bad host!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'host', 'username', 'auth_type', 'port']);

        $this->postJson('/api/v1/servers', ['name' => 'a', 'host' => 'h', 'username' => 'u', 'auth_type' => 'key'])
            ->assertJsonValidationErrors(['private_key' => 'Поле «закрытый ключ» обязательно']);
        $this->postJson('/api/v1/servers', ['name' => 'a', 'host' => 'h', 'username' => 'u', 'auth_type' => 'password'])
            ->assertJsonValidationErrors('password');
    }

    public function test_invalid_key_or_wrong_passphrase_rejected(): void
    {
        $base = ['name' => 'a', 'host' => 'h', 'username' => 'u', 'auth_type' => 'key'];

        $this->postJson('/api/v1/servers', [...$base, 'private_key' => 'not a key'])
            ->assertJsonValidationErrors('private_key');
        $this->postJson('/api/v1/servers', [...$base, 'private_key' => self::privateKey('right'), 'passphrase' => 'wrong'])
            ->assertJsonValidationErrors('private_key');
        $this->postJson('/api/v1/servers', [...$base, 'private_key' => EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH')])
            ->assertJsonValidationErrors(['private_key' => 'Это открытый ключ, нужен закрытый.']);
    }

    public function test_update_keeps_credentials_and_switching_auth_type_requires_new(): void
    {
        $server = Server::factory()->create(['password' => 'old']);

        $this->patchJson("/api/v1/servers/{$server->id}", ['name' => 'renamed'])
            ->assertOk()->assertJsonPath('data.name', 'renamed')->assertJsonPath('data.has_password', true);
        $this->assertSame('old', $server->fresh()->password);

        $this->patchJson("/api/v1/servers/{$server->id}", ['auth_type' => 'key'])->assertJsonValidationErrors('private_key');

        $this->patchJson("/api/v1/servers/{$server->id}", ['auth_type' => 'key', 'private_key' => self::privateKey()])
            ->assertOk()
            ->assertJsonPath('data.auth_type', 'key')
            ->assertJsonPath('data.has_private_key', true)
            ->assertJsonPath('data.has_password', false);
        $this->assertNull($server->fresh()->password);
    }

    public function test_changing_host_resets_fingerprint(): void
    {
        $server = Server::factory()->create(['host_fingerprint' => 'SHA256:abc']);

        $this->patchJson("/api/v1/servers/{$server->id}", ['description' => 'prod'])->assertJsonPath('data.host_fingerprint', 'SHA256:abc');
        $this->patchJson("/api/v1/servers/{$server->id}", ['host' => '10.1.1.1'])->assertJsonPath('data.host_fingerprint', null);
    }

    public function test_index_search_and_destroy(): void
    {
        Server::factory()->create(['name' => 'beta', 'host' => '10.0.0.2']);
        $alpha = Server::factory()->create(['name' => 'alpha', 'host' => 'alpha.example.com']);

        $this->getJson('/api/v1/servers')->assertOk()->assertJsonPath('data.*.name', ['alpha', 'beta']);
        $this->getJson('/api/v1/servers?search=example')->assertJsonPath('data.*.name', ['alpha']);

        $this->deleteJson("/api/v1/servers/{$alpha->id}")->assertNoContent();
        $this->getJson("/api/v1/servers/{$alpha->id}")->assertNotFound();
    }

    public function test_check_success_remembers_fingerprint(): void
    {
        $server = Server::factory()->create();
        $fake = $this->fakeSsh(new FakeSshConnector(rememberFingerprint: 'SHA256:xyz'));

        $this->postJson("/api/v1/servers/{$server->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.output', "Linux test 6.1\n up 3 days")
            ->assertJsonPath('data.server.host_fingerprint', 'SHA256:xyz')
            ->assertJsonPath('data.server.last_check.status', 'ok');

        $this->assertSame('uname -a && uptime', $fake->calls[0]['command']);
        $this->assertNotNull($server->fresh()->last_checked_at);
    }

    public function test_check_failures_are_saved(): void
    {
        $server = Server::factory()->create();

        $this->fakeSsh(new FakeSshConnector(throw: new SshConnectionException('Авторизация не удалась для пользователя root.')));
        $this->postJson("/api/v1/servers/{$server->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Авторизация не удалась для пользователя root.');

        $this->fakeSsh(new FakeSshConnector(throw: new HostFingerprintChangedException));
        $this->postJson("/api/v1/servers/{$server->id}/check")->assertJsonPath('data.error', 'Отпечаток хоста изменился');

        $this->fakeSsh(new FakeSshConnector(['exit_code' => 127, 'stdout' => '', 'stderr' => 'uname: not found']));
        $this->postJson("/api/v1/servers/{$server->id}/check")->assertJsonPath('data.error', 'Команда завершилась с кодом 127: uname: not found');

        $this->assertSame('failed', $server->fresh()->last_check_status);
    }

    public function test_generate_ed25519_key_on_create(): void
    {
        $response = $this->postJson('/api/v1/servers', [
            'name' => 'gen', 'host' => 'h', 'username' => 'root', 'auth_type' => 'key', 'generate_key' => true,
        ])->assertCreated()
            ->assertJsonPath('data.has_private_key', true)
            ->assertJsonPath('data.has_passphrase', false);

        $public = $response->json('data.public_key');
        $this->assertMatchesRegularExpression('#^ssh-ed25519 [A-Za-z0-9+/=]+ systemkeeper@gen$#', $public);
        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());

        $server = Server::find($response->json('data.id'));
        $this->assertSame($public, PublicKeyLoader::load($server->private_key)->getPublicKey()->toString('OpenSSH', ['comment' => 'systemkeeper@gen']));
    }

    public function test_regenerate_rsa_key_on_update_replaces_old(): void
    {
        $server = Server::factory()->create(['name' => 'srv']);

        $this->patchJson("/api/v1/servers/{$server->id}", ['auth_type' => 'key', 'generate_key' => true, 'key_type' => 'rsa'])
            ->assertOk()
            ->assertJsonPath('data.has_password', false);
        $first = $server->fresh();
        $this->assertStringStartsWith('ssh-rsa ', $first->public_key);

        $this->patchJson("/api/v1/servers/{$server->id}", ['generate_key' => true])->assertOk();
        $this->assertNotSame($first->private_key, $server->fresh()->private_key);
        $this->assertStringStartsWith('ssh-ed25519 ', $server->fresh()->public_key);

        $this->patchJson("/api/v1/servers/{$server->id}", ['generate_key' => true, 'key_type' => 'dsa'])->assertJsonValidationErrors('key_type');
    }

    public function test_pasted_key_gets_public_key_and_password_clears_it(): void
    {
        $response = $this->postJson('/api/v1/servers', [
            'name' => 'p', 'host' => 'h', 'username' => 'u', 'auth_type' => 'key', 'private_key' => self::privateKey('phrase'), 'passphrase' => 'phrase',
        ])->assertCreated();
        $this->assertStringStartsWith('ssh-ed25519 ', $response->json('data.public_key'));

        $this->patchJson('/api/v1/servers/'.$response->json('data.id'), ['auth_type' => 'password', 'password' => 'x'])
            ->assertOk()->assertJsonPath('data.public_key', null);
    }

    public function test_reset_fingerprint(): void
    {
        $server = Server::factory()->create(['host_fingerprint' => 'SHA256:abc']);

        $this->postJson("/api/v1/servers/{$server->id}/reset-fingerprint")->assertOk()->assertJsonPath('data.host_fingerprint', null);
    }
}
