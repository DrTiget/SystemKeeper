<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_user_from_options(): void
    {
        $this->artisan('user:create', ['--name' => 'Ann', '--email' => 'ann@example.com', '--password' => 'password1'])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'ann@example.com', 'name' => 'Ann', 'is_admin' => true]);
    }

    public function test_asks_missing_fields_interactively(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Имя', 'Bob')
            ->expectsQuestion('Email', 'bob@example.com')
            ->expectsQuestion('Пароль', 'password1')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'bob@example.com']);
    }

    public function test_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->artisan('user:create', ['--name' => 'X', '--email' => 'dup@example.com', '--password' => 'password1'])
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_rejects_short_password(): void
    {
        $this->artisan('user:create', ['--name' => 'X', '--email' => 'x@example.com', '--password' => 'short'])
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
