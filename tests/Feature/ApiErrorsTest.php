<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiErrorsTest extends TestCase
{
    use RefreshDatabase;

    private function assertMessage(int $status, string $message, $response): void
    {
        $response->assertStatus($status)->assertExactJson(['message' => $message]);
    }

    public function test_unauthenticated_is_russian(): void
    {
        $this->assertMessage(401, 'Требуется авторизация.', $this->getJson('/api/v1/servers'));
    }

    public function test_not_found_is_russian_for_unknown_route_and_missing_model(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertMessage(404, 'Не найдено.', $this->getJson('/api/v1/nonexistent'));
        $this->assertMessage(404, 'Не найдено.', $this->getJson('/api/v1/servers/999'));
    }

    public function test_method_not_allowed_is_russian(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->assertMessage(405, 'Метод не поддерживается.', $this->putJson('/api/v1/auth/me'));
    }

    public function test_forbidden_too_many_requests_and_server_error(): void
    {
        Route::get('/api/v1/_t/forbidden', fn () => abort(403));
        Route::get('/api/v1/_t/throttle', fn () => abort(429));
        Route::get('/api/v1/_t/boom', fn () => throw new \RuntimeException('secret internals'));
        Route::get('/api/v1/_t/own', fn () => abort(409, 'Свой текст.'));

        $this->assertMessage(403, 'Доступ запрещён.', $this->getJson('/api/v1/_t/forbidden'));
        $this->assertMessage(429, 'Слишком много запросов. Повторите позже.', $this->getJson('/api/v1/_t/throttle'));
        $this->assertMessage(409, 'Свой текст.', $this->getJson('/api/v1/_t/own'));

        config(['app.debug' => false]);
        $this->assertMessage(500, 'Внутренняя ошибка сервера.', $this->getJson('/api/v1/_t/boom'));
    }

    public function test_invalid_signed_link_is_russian_and_validation_untouched(): void
    {
        $this->assertMessage(403, 'Ссылка недействительна или истекла.', $this->getJson('/api/v1/backups/'.Backup::factory()->create()->id.'/file'));

        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_throttle_middleware_response_is_russian(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/heartbeat/unknown-token');
        }

        $this->assertMessage(429, 'Слишком много запросов. Повторите позже.', $this->getJson('/api/v1/heartbeat/unknown-token'));
    }
}
