<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ValidationLocaleTest extends TestCase
{
    use RefreshDatabase;

    /** Все тексты ошибок ответа одной строкой. */
    private function messages($response): string
    {
        return implode(' | ', array_merge(...array_values($response->json('errors'))));
    }

    private function assertRussian(string $text): void
    {
        $this->assertSame('ru', app()->getLocale());
        $this->assertDoesNotMatchRegularExpression('/\b(The|field|is required|must|when|selected|invalid)\b/i', $text, $text);
        $this->assertMatchesRegularExpression('/[а-яё]/iu', $text);
    }

    public function test_required_uses_russian_attribute_names(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/storage-targets', ['name' => 'a', 'type' => 'yandex_disk', 'base_path' => '/x'])
            ->assertUnprocessable();

        $this->assertSame(
            'Поле «токен» обязательно для заполнения.',
            $response->json('errors.token.0'),
        );
    }

    public function test_login_validation_is_russian(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [])->assertUnprocessable();

        $this->assertRussian($this->messages($response));
        $this->assertStringContainsString('«пароль»', $this->messages($response));
    }

    public function test_server_and_ssh_key_validation_is_russian(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $server = $this->postJson('/api/v1/servers', ['auth_type' => 'telnet', 'port' => 99999])->assertUnprocessable();
        $this->assertRussian($this->messages($server));

        $key = $this->postJson('/api/v1/ssh-keys', ['type' => 'dsa'])->assertUnprocessable();
        $this->assertRussian($this->messages($key));

        $monitor = $this->postJson('/api/v1/monitors', [])->assertUnprocessable();
        $this->assertRussian($this->messages($monitor));

        $backup = $this->postJson('/api/v1/backup-jobs', [])->assertUnprocessable();
        $this->assertRussian($this->messages($backup));
    }
}
