<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_login_validation_is_russian(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [])->assertUnprocessable();

        $this->assertRussian($this->messages($response));
        $this->assertSame('Поле «пароль» обязательно для заполнения.', $response->json('errors.password.0'));
    }
}
