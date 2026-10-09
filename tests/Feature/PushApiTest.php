<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->user = User::factory()->create());
    }

    private function sub(string $endpoint = 'https://push.example.com/abc'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKey', 'auth' => 'authkey']];
    }

    public function test_vapid_public_key(): void
    {
        config(['alerts.vapid.public_key' => null]);
        $this->getJson('/api/v1/push/vapid-public-key')->assertStatus(503);

        config(['alerts.vapid.public_key' => 'BPublic']);
        $this->getJson('/api/v1/push/vapid-public-key')->assertOk()->assertJsonPath('data.public_key', 'BPublic');
    }

    public function test_subscribe_is_idempotent_and_bound_to_user(): void
    {
        $this->postJson('/api/v1/push-subscriptions', $this->sub())->assertCreated();
        $this->postJson('/api/v1/push-subscriptions', ['keys' => ['p256dh' => 'New', 'auth' => 'a2']] + $this->sub())->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);
        $s = PushSubscription::first();
        $this->assertSame($this->user->id, $s->user_id);
        $this->assertSame('New', $s->p256dh);
    }

    public function test_subscribe_validation(): void
    {
        $this->postJson('/api/v1/push-subscriptions', [])->assertUnprocessable()->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
        $this->postJson('/api/v1/push-subscriptions', $this->sub('http://insecure.example.com'))->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    }

    public function test_unsubscribe_removes_only_own_subscription(): void
    {
        $this->postJson('/api/v1/push-subscriptions', $this->sub())->assertCreated();
        PushSubscription::create(['user_id' => User::factory()->create()->id, 'endpoint' => 'https://push.example.com/other', 'endpoint_hash' => PushSubscription::hash('https://push.example.com/other'), 'p256dh' => 'k', 'auth' => 'a']);

        $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => 'https://push.example.com/other'])->assertNoContent();
        $this->assertDatabaseCount('push_subscriptions', 2);

        $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => 'https://push.example.com/abc'])->assertNoContent();
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_device_tokens(): void
    {
        $this->postJson('/api/v1/device-tokens', ['platform' => 'android', 'token' => 'fcm-token-1'])->assertCreated()->assertJsonPath('data.platform', 'android');
        $this->postJson('/api/v1/device-tokens', ['platform' => 'ios', 'token' => 'fcm-token-1'])->assertOk();
        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertSame('ios', DeviceToken::first()->platform);

        $this->postJson('/api/v1/device-tokens', ['platform' => 'windows', 'token' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('platform');

        $this->deleteJson('/api/v1/device-tokens', ['token' => 'fcm-token-1'])->assertNoContent();
        $this->assertDatabaseCount('device_tokens', 0);
    }

    public function test_vapid_command_writes_keys_to_env_and_refuses_overwrite(): void
    {
        $dir = sys_get_temp_dir().'/sk-vapid-'.uniqid();
        mkdir($dir);
        $path = $dir.'/.env';
        file_put_contents($path, "APP_NAME=Test\n");
        $this->app->useEnvironmentPath($dir);

        try {
            $this->artisan('alerts:vapid-keys')->assertSuccessful();
            $env = file_get_contents($path);
            $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=\S{80,}$/m', $env);
            $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=\S{40,}$/m', $env);
            $this->assertStringContainsString('APP_NAME=Test', $env);

            $this->artisan('alerts:vapid-keys')->assertFailed();
            $this->assertSame($env, file_get_contents($path));

            $this->artisan('alerts:vapid-keys --force')->assertSuccessful();
            $this->assertNotSame($env, file_get_contents($path));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }
}
