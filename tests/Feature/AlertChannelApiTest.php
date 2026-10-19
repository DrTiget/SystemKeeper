<?php

namespace Tests\Feature;

use App\Mail\AlertMail;
use App\Models\AlertChannel;
use App\Models\AlertRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertChannelApiTest extends TestCase
{
    use RefreshDatabase;

    /** Поля AlertChannel из frontend/src/api/alerts.ts */
    private const CHANNEL = ['id', 'name', 'type', 'enabled', 'config', 'rules_count', 'last_sent_at', 'last_error', 'created_at'];

    protected function setUp(): void
    {
        parent::setUp();

        // каналами пользуются все пользователи, не только администраторы
        Sanctum::actingAs(User::factory()->create());
        // почта настроена (MAIL_* из .env), иначе канал «Почта» создать нельзя
        config(['mail.default' => 'smtp']);
    }

    private function telegram(array $config = []): AlertChannel
    {
        return AlertChannel::create(['name' => 'Дежурный чат', 'type' => 'telegram', 'config' => $config + ['bot_token' => '123:SECRET', 'chat_id' => '-1001']]);
    }

    public function test_requires_auth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/alert-channels')->assertUnauthorized();
    }

    public function test_create_telegram_like_frontend_hides_token(): void
    {
        // тело ровно как у ChannelFormModal.vue
        $response = $this->postJson('/api/v1/alert-channels', [
            'name' => 'Дежурный чат', 'type' => 'telegram', 'enabled' => true, 'config' => ['chat_id' => '-1001234', 'bot_token' => '123456:ABC-DEF'],
        ])->assertCreated()
            ->assertJsonStructure(['data' => self::CHANNEL])
            ->assertJsonPath('data.type', 'telegram')
            ->assertJsonPath('data.config', ['chat_id' => '-1001234', 'has_token' => true])
            ->assertJsonPath('data.rules_count', 0)
            ->assertJsonPath('data.last_error', null);

        $this->assertStringNotContainsString('ABC-DEF', $response->getContent());
        $this->assertSame('123456:ABC-DEF', AlertChannel::first()->config['bot_token']);
        // в БД настройки зашифрованы
        $this->assertStringNotContainsString('ABC-DEF', DB::table('alert_channels')->value('config'));
        $this->assertStringNotContainsString('-1001234', DB::table('alert_channels')->value('config'));
    }

    public function test_create_email_and_webhook(): void
    {
        $this->postJson('/api/v1/alert-channels', ['name' => 'Админам', 'type' => 'email', 'enabled' => true, 'config' => ['recipients' => ['a@example.com', 'b@example.com']]])
            ->assertCreated()->assertJsonPath('data.config', ['recipients' => ['a@example.com', 'b@example.com']]);

        $response = $this->postJson('/api/v1/alert-channels', ['name' => 'Slack', 'type' => 'webhook', 'enabled' => true, 'config' => ['url' => 'https://hooks.example.com/x', 'secret' => 'top-secret']])
            ->assertCreated()->assertJsonPath('data.config', ['url' => 'https://hooks.example.com/x', 'has_secret' => true]);
        $this->assertStringNotContainsString('top-secret', $response->getContent());

        // секрет вебхука необязателен
        $this->postJson('/api/v1/alert-channels', ['name' => 'n8n', 'type' => 'webhook', 'enabled' => true, 'config' => ['url' => 'https://n8n.example.com/hook']])
            ->assertCreated()->assertJsonPath('data.config.has_secret', false);
    }

    public function test_create_validates(): void
    {
        $this->postJson('/api/v1/alert-channels', ['name' => 'x', 'type' => 'telegram', 'config' => ['chat_id' => '1']])->assertUnprocessable()->assertJsonValidationErrors('config.bot_token');
        $this->postJson('/api/v1/alert-channels', ['name' => 'x', 'type' => 'telegram', 'config' => ['bot_token' => '', 'chat_id' => '1']])->assertUnprocessable()->assertJsonValidationErrors('config.bot_token');
        $this->postJson('/api/v1/alert-channels', ['name' => 'x', 'type' => 'email', 'config' => ['recipients' => []]])->assertUnprocessable()->assertJsonValidationErrors('config.recipients');
        $this->postJson('/api/v1/alert-channels', ['name' => 'x', 'type' => 'email', 'config' => ['recipients' => ['не-почта']]])->assertUnprocessable()->assertJsonValidationErrors('config.recipients.0');
        $this->postJson('/api/v1/alert-channels', ['name' => 'x', 'type' => 'webhook', 'config' => ['url' => 'ftp://x']])->assertUnprocessable()->assertJsonValidationErrors('config.url');
        $this->postJson('/api/v1/alert-channels', ['name' => 'x', 'type' => 'sms', 'config' => []])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/v1/alert-channels', ['type' => 'webhook', 'config' => ['url' => 'https://x.test']])->assertUnprocessable()->assertJsonValidationErrors('name');
        // поле чужого типа
        $this->postJson('/api/v1/alert-channels', ['name' => 'x', 'type' => 'webhook', 'config' => ['url' => 'https://x.test', 'bot_token' => '1']])->assertUnprocessable()->assertJsonValidationErrors('config.bot_token');
        $this->assertSame(0, AlertChannel::count());
    }

    public function test_numeric_chat_id_is_accepted(): void
    {
        $this->postJson('/api/v1/alert-channels', ['name' => 'x', 'type' => 'telegram', 'config' => ['bot_token' => '1:A', 'chat_id' => -100123]])
            ->assertCreated()->assertJsonPath('data.config.chat_id', '-100123');
    }

    public function test_patch_without_or_with_empty_secret_keeps_previous(): void
    {
        $channel = $this->telegram();

        // форма при правке без нового токена не шлёт bot_token
        $this->patchJson("/api/v1/alert-channels/{$channel->id}", ['name' => 'Новый чат', 'type' => 'telegram', 'enabled' => false, 'config' => ['chat_id' => '-777']])
            ->assertOk()->assertJsonPath('data.name', 'Новый чат')->assertJsonPath('data.enabled', false)->assertJsonPath('data.config', ['chat_id' => '-777', 'has_token' => true]);
        $this->patchJson("/api/v1/alert-channels/{$channel->id}", ['config' => ['chat_id' => '-777', 'bot_token' => '']])->assertOk()->assertJsonPath('data.config.has_token', true);

        $channel->refresh();
        $this->assertSame('123:SECRET', $channel->config['bot_token']);
        $this->assertSame('-777', $channel->config['chat_id']);

        // новый токен заменяет прежний
        $this->patchJson("/api/v1/alert-channels/{$channel->id}", ['config' => ['bot_token' => '999:NEW']])->assertOk();
        $this->assertSame('999:NEW', $channel->refresh()->config['bot_token']);
    }

    public function test_patch_webhook_empty_secret_keeps_previous(): void
    {
        $channel = AlertChannel::create(['name' => 'h', 'type' => 'webhook', 'config' => ['url' => 'https://a.test', 'secret' => 'old']]);

        $this->patchJson("/api/v1/alert-channels/{$channel->id}", ['type' => 'webhook', 'config' => ['url' => 'https://b.test', 'secret' => '']])->assertOk()->assertJsonPath('data.config', ['url' => 'https://b.test', 'has_secret' => true]);
        $this->assertSame(['url' => 'https://b.test', 'secret' => 'old'], $channel->refresh()->config);
    }

    public function test_changing_type_drops_old_settings_and_requires_new_ones(): void
    {
        $channel = $this->telegram();

        $this->patchJson("/api/v1/alert-channels/{$channel->id}", ['type' => 'email', 'config' => []])->assertUnprocessable()->assertJsonValidationErrors('config.recipients');
        $this->patchJson("/api/v1/alert-channels/{$channel->id}", ['type' => 'email', 'config' => ['recipients' => ['a@example.com']]])
            ->assertOk()->assertJsonPath('data.type', 'email')->assertJsonPath('data.config', ['recipients' => ['a@example.com']]);
        $this->assertSame(['recipients' => ['a@example.com']], $channel->refresh()->config);

        // обратно в Telegram — токен придётся ввести заново
        $this->patchJson("/api/v1/alert-channels/{$channel->id}", ['type' => 'telegram', 'config' => ['chat_id' => '1']])->assertUnprocessable()->assertJsonValidationErrors('config.bot_token');
    }

    public function test_index_has_rules_count_and_wrapper(): void
    {
        $a = $this->telegram();
        $b = AlertChannel::create(['name' => 'Почта', 'type' => 'email', 'config' => ['recipients' => ['a@example.com']]]);
        AlertRule::create(['name' => 'r', 'kind' => 'monitor_down', 'severity' => 'critical'])->channels()->sync([$a->id]);

        $this->getJson('/api/v1/alert-channels')->assertOk()
            ->assertJsonStructure(['data' => ['*' => self::CHANNEL]])
            ->assertJsonPath('data.0.id', $a->id)
            ->assertJsonPath('data.0.rules_count', 1)
            ->assertJsonPath('data.1.id', $b->id)
            ->assertJsonPath('data.1.rules_count', 0);
    }

    public function test_delete_detaches_from_rules(): void
    {
        $channel = $this->telegram();
        $rule = AlertRule::create(['name' => 'r', 'kind' => 'monitor_down', 'severity' => 'critical']);
        $rule->channels()->sync([$channel->id]);

        $this->deleteJson("/api/v1/alert-channels/{$channel->id}")->assertNoContent();

        $this->assertSame(0, AlertChannel::count());
        $this->assertSame(0, $rule->channels()->count());
        $this->deleteJson("/api/v1/alert-channels/{$channel->id}")->assertNotFound();
    }

    public function test_telegram_test_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $channel = $this->telegram();

        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertExactJson(['data' => ['ok' => true, 'error' => null]]);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.telegram.org/bot123:SECRET/sendMessage'
                && $request['chat_id'] === '-1001'
                && str_contains($request['text'], 'Тестовое сообщение');
        });
        $channel->refresh();
        $this->assertNotNull($channel->last_sent_at);
        $this->assertNull($channel->last_error);
    }

    public function test_telegram_error_is_reported_without_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);
        $channel = $this->telegram();

        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error', 'Telegram: Bad Request: chat not found');
        $this->assertSame('Telegram: Bad Request: chat not found', $channel->refresh()->last_error);
    }

    public function test_connection_error_does_not_leak_token(): void
    {
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host for https://api.telegram.org/bot123:SECRET/sendMessage')]);
        $channel = $this->telegram();

        $response = $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertJsonPath('data.ok', false);

        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertStringNotContainsString('SECRET', (string) $channel->refresh()->last_error);
    }

    public function test_email_test_message(): void
    {
        Mail::fake();
        $channel = AlertChannel::create(['name' => 'Почта', 'type' => 'email', 'config' => ['recipients' => ['a@example.com', 'b@example.com']]]);

        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertJsonPath('data.ok', true);

        Mail::assertSent(AlertMail::class, function (AlertMail $mail) {
            // письмо текстовое и отрисовывается
            $mail->assertSeeInText('Канал «Почта» настроен');
            $mail->assertHasSubject('[SystemKeeper] ✅ Тестовое сообщение SystemKeeper');

            return $mail->hasTo('a@example.com') && $mail->hasTo('b@example.com');
        });
    }

    public function test_webhook_test_is_signed_with_hmac(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok')]);
        $channel = AlertChannel::create(['name' => 'h', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/x', 'secret' => 'top-secret']]);

        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertJsonPath('data.ok', true);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://hooks.example.com/x'
                && $request->header('X-Signature') === [hash_hmac('sha256', $request->body(), 'top-secret')]
                && $request->header('Content-Type')[0] === 'application/json'
                && ($request->data()['event'] ?? null) === 'test';
        });
    }

    public function test_webhook_without_secret_has_no_signature_and_error_status_is_reported(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('boom', 502)]);
        $channel = AlertChannel::create(['name' => 'h', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/x']]);

        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()
            ->assertJsonPath('data.ok', false)->assertJsonPath('data.error', 'Вебхук ответил кодом 502');

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Signature'));
    }

    public function test_test_of_unknown_channel_is_404(): void
    {
        $this->postJson('/api/v1/alert-channels/999/test')->assertNotFound();
    }
}
