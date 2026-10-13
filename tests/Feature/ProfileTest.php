<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email' => 'me@example.com', 'password' => 'old-password']);
        $this->token = $this->user->createToken('web')->plainTextToken;
    }

    private function api(): static
    {
        return $this->withToken($this->token);
    }

    public function test_profile_requires_auth(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
        $this->patchJson('/api/v1/profile', ['name' => 'x'])->assertUnauthorized();
    }

    public function test_show_returns_user_with_default_settings(): void
    {
        $this->api()->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.email', 'me@example.com')
            ->assertJsonPath('data.settings', ['theme' => 'system', 'timezone' => 'Europe/Moscow'])
            ->assertJsonMissingPath('data.password');
    }

    public function test_update_name_and_merge_settings(): void
    {
        $this->api()->patchJson('/api/v1/profile', ['name' => 'Новое имя', 'settings' => ['theme' => 'dark']])
            ->assertOk()
            ->assertJsonPath('data.name', 'Новое имя')
            ->assertJsonPath('data.settings', ['theme' => 'dark', 'timezone' => 'Europe/Moscow']);

        $this->api()->patchJson('/api/v1/profile', ['settings' => ['timezone' => 'Asia/Yekaterinburg']])
            ->assertOk()
            ->assertJsonPath('data.settings', ['theme' => 'dark', 'timezone' => 'Asia/Yekaterinburg']);

        $this->assertSame('Новое имя', $this->user->fresh()->name);
    }

    public function test_update_validates_settings(): void
    {
        $response = $this->api()->patchJson('/api/v1/profile', [
            'name' => '',
            'settings' => ['theme' => 'neon', 'timezone' => 'Mars/Base', 'unknown' => 1],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'settings', 'settings.theme', 'settings.timezone']);

        $this->assertSame('Поле «имя» обязательно для заполнения.', $response->json('errors.name.0'));
    }

    public function test_email_change_requires_current_password_and_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->api()->patchJson('/api/v1/profile', ['email' => 'new@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->api()->patchJson('/api/v1/profile', ['email' => 'new@example.com', 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->api()->patchJson('/api/v1/profile', ['email' => 'taken@example.com', 'current_password' => 'old-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->api()->patchJson('/api/v1/profile', ['email' => 'new@example.com', 'current_password' => 'old-password'])
            ->assertOk()->assertJsonPath('data.email', 'new@example.com');
    }

    public function test_change_password_revokes_other_tokens(): void
    {
        $other = $this->user->createToken('android')->plainTextToken;

        $this->api()->putJson('/api/v1/profile/password', [
            'current_password' => 'wrong',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->api()->putJson('/api/v1/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'mismatch',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->api()->putJson('/api/v1/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('new-password', $this->user->fresh()->password));
        $this->assertSame(['web'], $this->user->tokens()->pluck('name')->all());
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_tokens_list_and_revoke(): void
    {
        $this->user->createToken('android');
        $foreign = User::factory()->create()->createToken('web')->accessToken;

        $list = $this->api()->getJson('/api/v1/profile/tokens')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'android')
            ->assertJsonPath('data.0.current', false)
            ->assertJsonPath('data.1.current', true)
            ->assertJsonMissingPath('data.0.token')
            ->json('data');

        $this->api()->deleteJson('/api/v1/profile/tokens/'.$foreign->id)->assertNotFound();
        $this->api()->deleteJson('/api/v1/profile/tokens/'.$list[0]['id'])->assertNoContent();
        $this->assertSame(['web'], $this->user->tokens()->pluck('name')->all());
    }

    public function test_revoke_other_tokens(): void
    {
        $this->user->createToken('android');
        $this->user->createToken('ios');

        $this->api()->deleteJson('/api/v1/profile/tokens')->assertNoContent();

        $this->assertSame(['web'], $this->user->tokens()->pluck('name')->all());
    }
}
