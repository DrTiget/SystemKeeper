<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_user(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
            'device_name' => 'tests',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'created_at']])
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.password');
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'tests']);
    }

    public function test_login_with_wrong_password_returns_422(): void
    {
        $user = User::factory()->create(['password' => 'secret-pass']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_login_validates_input(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        RateLimiter::clear('a@example.com|127.0.0.1');
        $payload = ['email' => 'a@example.com', 'password' => 'wrong'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $payload)->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(429);
    }

    public function test_me_with_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_me_without_token_returns_json_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonStructure(['message']);
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_cors_headers_for_api(): void
    {
        $this->options('/api/v1/auth/login', [], [
            'Origin' => 'http://localhost:3000',
            'Access-Control-Request-Method' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin');
    }
}
