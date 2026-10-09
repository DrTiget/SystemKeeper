<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\SshKey;
use App\Models\User;
use App\Services\Ssh\SshKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use Tests\TestCase;

class SshKeyApiTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_requires_authentication(): void
    {
        $key = SshKey::factory()->create();

        $this->getJson('/api/v1/ssh-keys')->assertUnauthorized();
        $this->postJson('/api/v1/ssh-keys', ['name' => 'x'])->assertUnauthorized();
        $this->getJson("/api/v1/ssh-keys/{$key->id}")->assertUnauthorized();
        $this->getJson("/api/v1/ssh-keys/{$key->id}/public")->assertUnauthorized();
        $this->deleteJson("/api/v1/ssh-keys/{$key->id}")->assertUnauthorized();
    }

    public function test_generates_ed25519_by_default(): void
    {
        $this->login();

        $response = $this->postJson('/api/v1/ssh-keys', ['name' => 'prod'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'prod')
            ->assertJsonPath('data.type', 'ed25519')
            ->assertJsonPath('data.has_passphrase', false);

        $this->assertStringStartsWith('ssh-ed25519 ', $response->json('data.public_key'));
        $this->assertStringEndsWith(' prod', $response->json('data.public_key'));
        $this->assertStringStartsWith('SHA256:', $response->json('data.fingerprint'));
        $this->assertDatabaseHas('ssh_keys', ['name' => 'prod', 'type' => 'ed25519']);
    }

    public function test_generates_rsa_4096(): void
    {
        $this->login();

        $response = $this->postJson('/api/v1/ssh-keys', ['name' => 'rsa-key', 'type' => 'rsa'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'rsa');

        $this->assertStringStartsWith('ssh-rsa ', $response->json('data.public_key'));
        $this->assertSame(4096, SshKey::first()->private_key ? PublicKeyLoader::load(SshKey::first()->private_key)->getLength() : 0);
    }

    public function test_imports_private_key_and_derives_public_key(): void
    {
        $this->login();
        $material = app(SshKeyService::class)->generate('ed25519');

        $this->postJson('/api/v1/ssh-keys', ['name' => 'imported', 'private_key' => $material->privateKey])
            ->assertCreated()
            ->assertJsonPath('data.type', 'ed25519')
            ->assertJsonPath('data.fingerprint', $material->fingerprint);

        $this->assertSame(trim($material->privateKey), SshKey::first()->private_key);
    }

    public function test_imports_key_with_passphrase(): void
    {
        $this->login();
        $encrypted = EC::createKey('Ed25519')->withPassword('pw-123')->toString('OpenSSH');

        $this->postJson('/api/v1/ssh-keys', ['name' => 'locked', 'private_key' => $encrypted])
            ->assertUnprocessable()->assertJsonValidationErrors('private_key');
        $this->postJson('/api/v1/ssh-keys', ['name' => 'locked', 'private_key' => $encrypted, 'passphrase' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('private_key');

        $this->postJson('/api/v1/ssh-keys', ['name' => 'locked', 'private_key' => $encrypted, 'passphrase' => 'pw-123'])
            ->assertCreated()
            ->assertJsonPath('data.has_passphrase', true)
            ->assertJsonMissingPath('data.passphrase');
        $this->assertSame('pw-123', SshKey::first()->passphrase);
    }

    public function test_invalid_private_key_is_rejected(): void
    {
        $this->login();

        $this->postJson('/api/v1/ssh-keys', ['name' => 'bad', 'private_key' => 'not a key'])
            ->assertUnprocessable()->assertJsonValidationErrors('private_key');
        $this->assertDatabaseCount('ssh_keys', 0);
    }

    public function test_validates_name_and_type(): void
    {
        $this->login();
        SshKey::factory()->create(['name' => 'dup']);

        $this->postJson('/api/v1/ssh-keys', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/ssh-keys', ['name' => 'dup'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/ssh-keys', ['name' => 'new', 'type' => 'dsa'])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_secrets_are_never_exposed_and_stored_encrypted(): void
    {
        $this->login();
        $material = app(SshKeyService::class)->generate('ed25519');
        $key = SshKey::factory()->create(['private_key' => $material->privateKey, 'passphrase' => 'top-secret']);
        $paths = ['/api/v1/ssh-keys', "/api/v1/ssh-keys/{$key->id}", "/api/v1/ssh-keys/{$key->id}/public"];

        foreach ($paths as $path) {
            $body = $this->getJson($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('PRIVATE KEY', $body);
            $this->assertStringNotContainsString('top-secret', $body);
            $this->assertStringNotContainsString('private_key', $body);
        }
        $this->putJson("/api/v1/ssh-keys/{$key->id}", ['name' => 'renamed'])->assertOk()->assertJsonMissingPath('data.private_key');

        $raw = DB::table('ssh_keys')->where('id', $key->id)->first();
        $this->assertStringNotContainsString('PRIVATE KEY', $raw->private_key);
        $this->assertStringNotContainsString('top-secret', $raw->passphrase);
        $this->assertArrayNotHasKey('private_key', $key->fresh()->toArray());
    }

    public function test_lists_with_pagination_and_shows_servers_count(): void
    {
        $this->login();
        $key = SshKey::factory()->create();
        SshKey::factory()->count(2)->create();
        Server::factory()->count(2)->create(['ssh_key_id' => $key->id]);

        $this->getJson('/api/v1/ssh-keys?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
        $this->getJson("/api/v1/ssh-keys/{$key->id}")->assertOk()->assertJsonPath('data.servers_count', 2);
    }

    public function test_update_changes_only_name(): void
    {
        $this->login();
        $key = SshKey::factory()->create(['name' => 'old']);
        SshKey::factory()->create(['name' => 'taken']);

        $this->putJson("/api/v1/ssh-keys/{$key->id}", ['name' => 'taken'])->assertUnprocessable();
        $this->putJson("/api/v1/ssh-keys/{$key->id}", ['name' => 'old'])->assertOk();
        $this->putJson("/api/v1/ssh-keys/{$key->id}", ['name' => 'new', 'fingerprint' => 'hack', 'type' => 'rsa'])
            ->assertOk()->assertJsonPath('data.name', 'new');

        $fresh = $key->fresh();
        $this->assertSame($key->fingerprint, $fresh->fingerprint);
        $this->assertSame('ed25519', $fresh->type);
    }

    public function test_public_key_endpoint(): void
    {
        $this->login();
        $key = SshKey::factory()->create();

        $this->getJson("/api/v1/ssh-keys/{$key->id}/public")
            ->assertOk()
            ->assertJsonPath('data.public_key', $key->public_key)
            ->assertJsonPath('data.fingerprint', $key->fingerprint);
    }

    public function test_delete_unused_key(): void
    {
        $this->login();
        $key = SshKey::factory()->create();

        $this->deleteJson("/api/v1/ssh-keys/{$key->id}")->assertNoContent();
        $this->assertDatabaseMissing('ssh_keys', ['id' => $key->id]);
    }

    public function test_cannot_delete_key_used_by_servers(): void
    {
        $this->login();
        $key = SshKey::factory()->create();
        Server::factory()->create(['ssh_key_id' => $key->id]);

        $this->deleteJson("/api/v1/ssh-keys/{$key->id}")->assertStatus(409);
        $this->assertDatabaseHas('ssh_keys', ['id' => $key->id]);
    }

    public function test_show_missing_returns_404(): void
    {
        $this->login();

        $this->getJson('/api/v1/ssh-keys/999')->assertNotFound();
    }
}
