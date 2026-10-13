<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Админ', 'email' => 'admin@example.com']);
    }

    public function test_only_admins_manage_users(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/users', [])->assertForbidden();
        $this->deleteJson('/api/v1/users/'.$this->admin->id)->assertForbidden();
    }

    public function test_index_sorted_with_search_and_pagination(): void
    {
        User::factory()->create(['name' => 'Борис', 'email' => 'boris@example.com']);
        User::factory()->create(['name' => 'Анна', 'email' => 'anna@example.com']);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Админ', 'Анна', 'Борис'])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonMissingPath('data.0.password');

        $this->getJson('/api/v1/users?search=boris')->assertJsonPath('data.*.name', ['Борис']);
        $this->getJson('/api/v1/users?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 3);
    }

    public function test_store_creates_user_who_can_log_in(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', ['name' => 'Иван', 'email' => 'ivan@example.com', 'password' => 'password1'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Иван')
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.settings.theme', 'system');

        $this->postJson('/api/v1/auth/login', ['email' => 'ivan@example.com', 'password' => 'password1'])->assertOk();
    }

    public function test_store_validates(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/users', ['email' => 'admin@example.com', 'password' => 'short', 'is_admin' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'is_admin']);

        $this->assertSame('Поле «имя» обязательно для заполнения.', $response->json('errors.name.0'));
    }

    public function test_update_and_password_change_revokes_sessions(): void
    {
        $user = User::factory()->create(['email' => 'u@example.com']);
        $user->createToken('web');
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/users/{$user->id}", ['name' => 'Новое', 'is_admin' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'Новое')
            ->assertJsonPath('data.is_admin', true);
        $this->assertSame(1, $user->tokens()->count());

        $this->patchJson("/api/v1/users/{$user->id}", ['email' => 'admin@example.com'])->assertJsonValidationErrors('email');
        $this->patchJson("/api/v1/users/{$user->id}", ['email' => 'u@example.com'])->assertOk();

        $this->patchJson("/api/v1/users/{$user->id}", ['password' => 'new-password'])->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_admin_cannot_demote_or_delete_self(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/users/{$this->admin->id}", ['is_admin' => false])
            ->assertUnprocessable()
            ->assertJsonPath('errors.is_admin.0', 'Нельзя снять права администратора с себя.');

        $this->deleteJson("/api/v1/users/{$this->admin->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Нельзя удалить самого себя.');
    }

    public function test_destroy_removes_user_and_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('web');
        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/v1/users/{$user->id}")->assertNoContent();

        $this->assertModelMissing($user);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->getJson("/api/v1/users/{$user->id}")->assertNotFound();
    }

    public function test_profile_cannot_grant_admin(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', ['name' => 'X', 'is_admin' => true])->assertOk()->assertJsonPath('data.is_admin', false);
    }
}
