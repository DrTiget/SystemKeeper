<?php

namespace Tests\Feature;

use App\Mail\AlertMail;
use App\Mail\TestMail;
use App\Models\AlertChannel;
use App\Models\Setting;
use App\Models\User;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\ChannelSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/settings/notifications';

    /** Тело ровно как у NotificationsView.vue */
    private const FORM = [
        'mail' => ['host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'robot@example.com', 'password' => 'smtp-secret',
            'from_address' => 'alerts@example.com', 'from_name' => 'SystemKeeper'],
        'telegram' => ['api_url' => 'https://tg.example.com', 'proxy' => ['enabled' => true, 'type' => 'socks5', 'host' => '10.0.0.5', 'port' => 1080, 'username' => 'u', 'password' => 'proxy-secret']],
    ];

    /** Отправитель письма как он лежит в Mailable (его применяет buildFrom) */
    private const FROM = [['name' => 'SystemKeeper', 'address' => 'alerts@example.com']];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function save(array $override = []): void
    {
        $this->putJson(self::URL, array_replace_recursive(self::FORM, $override))->assertOk();
    }

    public function test_requires_admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $this->getJson(self::URL)->assertForbidden();
        $this->putJson(self::URL, self::FORM)->assertForbidden();
        $this->postJson(self::URL.'/test-mail', ['to' => 'a@example.com'])->assertForbidden();
        $this->postJson(self::URL.'/test-telegram')->assertForbidden();
        $this->assertSame(0, Setting::count());

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson(self::URL)->assertUnauthorized();
    }

    public function test_defaults(): void
    {
        $this->getJson(self::URL)->assertOk()->assertExactJson(['data' => [
            'mail' => ['configured' => false, 'host' => null, 'port' => 587, 'encryption' => 'tls', 'username' => null, 'has_password' => false,
                'from_address' => null, 'from_name' => null, 'source' => 'none'],
            'telegram' => ['api_url' => 'https://api.telegram.org', 'proxy' => ['enabled' => false, 'type' => 'http', 'host' => null, 'port' => null, 'username' => null, 'has_password' => false]],
        ]]);
    }

    public function test_mail_source_env_when_mailer_is_real(): void
    {
        config(['mail.default' => 'smtp']);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.mail.configured', true)->assertJsonPath('data.mail.source', 'env');
    }

    public function test_save_hides_secrets_and_encrypts_them(): void
    {
        $response = $this->putJson(self::URL, self::FORM)->assertOk()
            ->assertJsonPath('data.mail', ['configured' => true, 'host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'robot@example.com',
                'has_password' => true, 'from_address' => 'alerts@example.com', 'from_name' => 'SystemKeeper', 'source' => 'settings'])
            ->assertJsonPath('data.telegram', ['api_url' => 'https://tg.example.com', 'proxy' => ['enabled' => true, 'type' => 'socks5', 'host' => '10.0.0.5', 'port' => 1080, 'username' => 'u', 'has_password' => true]]);

        foreach ([$response->getContent(), $this->getJson(self::URL)->getContent()] as $body) {
            $this->assertStringNotContainsString('smtp-secret', $body);
            $this->assertStringNotContainsString('proxy-secret', $body);
        }
        $raw = DB::table('settings')->value('value');
        foreach (['smtp-secret', 'proxy-secret', 'smtp.example.com'] as $plain) {
            $this->assertStringNotContainsString($plain, $raw);
        }
        $this->assertSame('smtp-secret', Setting::firstWhere('key', 'notifications')->value['mail']['password']);
    }

    public function test_empty_or_missing_password_keeps_previous_and_clear_flag_erases(): void
    {
        $this->save();
        $stored = fn () => Setting::firstWhere('key', 'notifications')->value;

        // форма без нового пароля: поле пустое (ConvertEmptyStringsToNull) или не передано
        $this->save(['mail' => ['password' => '', 'host' => 'smtp2.example.com'], 'telegram' => ['proxy' => ['password' => null]]]);
        $this->assertSame('smtp-secret', $stored()['mail']['password']);
        $this->assertSame('proxy-secret', $stored()['telegram']['proxy']['password']);
        $this->assertSame('smtp2.example.com', $stored()['mail']['host']);

        $body = self::FORM;
        unset($body['mail']['password'], $body['telegram']['proxy']['password']);
        $this->putJson(self::URL, $body)->assertOk()->assertJsonPath('data.mail.has_password', true);
        $this->assertSame('smtp-secret', $stored()['mail']['password']);

        // новый пароль заменяет
        $this->save(['mail' => ['password' => 'new-secret']]);
        $this->assertSame('new-secret', $stored()['mail']['password']);

        // clear_password стирает, не трогая второй раздел
        $this->putJson(self::URL, ['mail' => ['clear_password' => true]])->assertOk()->assertJsonPath('data.mail.has_password', false)->assertJsonPath('data.telegram.proxy.has_password', true);
        $this->assertNull($stored()['mail']['password']);
        $this->assertSame('proxy-secret', $stored()['telegram']['proxy']['password']);
    }

    public function test_partial_update_keeps_other_sections(): void
    {
        $this->save();

        $this->putJson(self::URL, ['telegram' => ['proxy' => ['enabled' => false]]])->assertOk()
            ->assertJsonPath('data.telegram.proxy.enabled', false)
            ->assertJsonPath('data.telegram.proxy.host', '10.0.0.5')
            ->assertJsonPath('data.mail.host', 'smtp.example.com');
        // пустой адрес Bot API — снова по умолчанию; пустой хост отключает SMTP из настроек
        $this->putJson(self::URL, ['telegram' => ['api_url' => ''], 'mail' => ['host' => '']])->assertOk()
            ->assertJsonPath('data.telegram.api_url', 'https://api.telegram.org')
            ->assertJsonPath('data.mail.source', 'none');
    }

    public function test_validation(): void
    {
        $this->putJson(self::URL, ['mail' => ['host' => 'smtp.example.com']])->assertUnprocessable()->assertJsonValidationErrors('mail.from_address');
        $this->putJson(self::URL, ['mail' => ['host' => 'bad host/x', 'port' => 70000, 'encryption' => 'starttls', 'from_address' => 'не-почта']])->assertUnprocessable()
            ->assertJsonValidationErrors(['mail.host', 'mail.port', 'mail.encryption', 'mail.from_address']);
        $this->putJson(self::URL, ['telegram' => ['api_url' => 'ftp://x']])->assertUnprocessable()->assertJsonValidationErrors('telegram.api_url');
        $this->putJson(self::URL, ['telegram' => ['proxy' => ['enabled' => true, 'type' => 'http']]])->assertUnprocessable()->assertJsonValidationErrors(['telegram.proxy.host', 'telegram.proxy.port']);
        $this->putJson(self::URL, ['telegram' => ['proxy' => ['enabled' => true, 'type' => 'ftp', 'host' => 'h', 'port' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('telegram.proxy.type');
        $this->assertSame(0, Setting::count());
    }

    public function test_alert_mail_goes_through_settings_smtp_with_configured_from(): void
    {
        Mail::fake();
        $this->save();
        $channel = AlertChannel::create(['name' => 'Почта', 'type' => 'email', 'config' => ['recipients' => ['ops@example.com']]]);

        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertJsonPath('data.ok', true);

        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo('ops@example.com') && $mail->from === self::FROM);
        // SMTP собран из настроек
        $this->assertSame(['transport' => 'smtp', 'host' => 'smtp.example.com', 'port' => 465, 'username' => 'robot@example.com', 'password' => 'smtp-secret'],
            collect(config('mail.mailers.settings'))->only(['transport', 'host', 'port', 'username', 'password'])->all());
        $this->assertSame('smtps', config('mail.mailers.settings.scheme'));
    }

    public function test_smtp_encryption_modes(): void
    {
        Mail::fake();
        $cases = [
            'ssl' => ['scheme' => 'smtps'],
            'tls' => ['scheme' => 'smtp', 'require_tls' => true],
            'none' => ['scheme' => 'smtp', 'auto_tls' => false],
        ];

        foreach ($cases as $encryption => $expected) {
            $this->save(['mail' => ['encryption' => $encryption]]);
            $this->postJson(self::URL.'/test-mail', ['to' => 'me@example.com'])->assertOk()->assertJsonPath('data.ok', true);

            $config = config('mail.mailers.settings');
            $this->assertSame($expected, collect($config)->only(['scheme', 'require_tls', 'auto_tls'])->all(), $encryption);
        }
    }

    public function test_test_mail_like_frontend(): void
    {
        Mail::fake();
        $this->save();

        $this->postJson(self::URL.'/test-mail', ['to' => 'me@example.com'])->assertOk()->assertExactJson(['data' => ['ok' => true, 'error' => null]]);

        Mail::assertSent(TestMail::class, function (TestMail $mail) {
            $mail->assertSeeInText('Почта настроена');

            return $mail->hasTo('me@example.com') && $mail->from === self::FROM;
        });
    }

    public function test_test_mail_from_env_mailer_has_no_explicit_from(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $this->postJson(self::URL.'/test-mail', ['to' => 'me@example.com'])->assertOk()->assertJsonPath('data.ok', true);

        Mail::assertSent(TestMail::class, fn (TestMail $mail) => $mail->hasTo('me@example.com') && $mail->from === []);
    }

    public function test_test_mail_unconfigured_and_invalid(): void
    {
        Mail::fake();

        $this->postJson(self::URL.'/test-mail', ['to' => 'me@example.com'])->assertUnprocessable()->assertJsonValidationErrors(['to'])
            ->assertJsonPath('errors.to.0', 'Почта не настроена: Настройки → Уведомления');
        $this->postJson(self::URL.'/test-mail', ['to' => 'не-почта'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->postJson(self::URL.'/test-mail', [])->assertUnprocessable()->assertJsonValidationErrors('to');
        Mail::assertNothingSent();
    }

    public function test_test_mail_failure_is_reported_without_password(): void
    {
        $this->save(['mail' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'password' => 'smtp-secret']]);
        // настоящий SMTP на закрытом порту — ошибка соединения, пароль в тексте не светится
        $response = $this->postJson(self::URL.'/test-mail', ['to' => 'me@example.com'])->assertOk()->assertJsonPath('data.ok', false);

        $this->assertNotEmpty($response->json('data.error'));
        $this->assertStringNotContainsString('smtp-secret', $response->getContent());
    }

    public function test_email_channel_requires_configured_mail(): void
    {
        $body = ['name' => 'Админам', 'type' => 'email', 'enabled' => true, 'config' => ['recipients' => ['a@example.com']]];

        $this->postJson('/api/v1/alert-channels', $body)->assertUnprocessable()->assertJsonPath('errors.type.0', 'Почта не настроена: Настройки → Уведомления');
        $this->assertSame(0, AlertChannel::count());

        // webhook и telegram от почты не зависят
        $this->postJson('/api/v1/alert-channels', ['name' => 'h', 'type' => 'webhook', 'config' => ['url' => 'https://x.test']])->assertCreated();

        // существующий канал нельзя перевести в почту, а тест email-канала — 422
        $webhook = AlertChannel::first();
        $this->patchJson("/api/v1/alert-channels/{$webhook->id}", ['type' => 'email', 'config' => ['recipients' => ['a@example.com']]])->assertUnprocessable()->assertJsonValidationErrors('type');
        $email = AlertChannel::create(['name' => 'Почта', 'type' => 'email', 'config' => ['recipients' => ['a@example.com']]]);
        $this->postJson("/api/v1/alert-channels/{$email->id}/test")->assertUnprocessable()->assertJsonValidationErrors('type');
        // но переименовать уже существующий — можно
        $this->patchJson("/api/v1/alert-channels/{$email->id}", ['name' => 'Почта 2'])->assertOk();

        $this->save();
        $this->postJson('/api/v1/alert-channels', $body)->assertCreated();
    }

    public function test_sender_refuses_email_without_mail(): void
    {
        Mail::fake();
        $channel = AlertChannel::create(['name' => 'Почта', 'type' => 'email', 'config' => ['recipients' => ['a@example.com']]]);

        $this->expectExceptionMessage('Почта не настроена: Настройки → Уведомления');
        app(ChannelSender::class)->send($channel, AlertMessage::test('Почта'));
    }

    public function test_telegram_message_goes_to_custom_api_url_through_proxy(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = [$request->url(), $options['proxy'] ?? null];

            return Http::response(['ok' => true]);
        });
        $this->save();
        $channel = AlertChannel::create(['name' => 'Чат', 'type' => 'telegram', 'config' => ['bot_token' => '123:SECRET', 'chat_id' => '-1001']]);

        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertJsonPath('data.ok', true);

        $this->assertSame([['https://tg.example.com/bot123:SECRET/sendMessage', 'socks5h://u:proxy-secret@10.0.0.5:1080']], $seen);
    }

    public function test_telegram_defaults_without_settings(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = [$request->url(), $options['proxy'] ?? null];

            return Http::response(['ok' => true]);
        });
        $channel = AlertChannel::create(['name' => 'Чат', 'type' => 'telegram', 'config' => ['bot_token' => '123:SECRET', 'chat_id' => '-1001']]);

        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertJsonPath('data.ok', true);

        $this->assertSame([['https://api.telegram.org/bot123:SECRET/sendMessage', null]], $seen);
    }

    public function test_http_proxy_url_is_encoded_and_disabled_proxy_is_ignored(): void
    {
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = $options['proxy'] ?? null;

            return Http::response('');
        });
        $this->save(['telegram' => ['proxy' => ['type' => 'http', 'username' => 'us er', 'password' => 'p@ss:w/d']]]);
        $this->postJson(self::URL.'/test-telegram')->assertOk();
        $this->save(['telegram' => ['proxy' => ['enabled' => false]]]);
        $this->postJson(self::URL.'/test-telegram')->assertOk();

        $this->assertSame(['http://us%20er:p%40ss%3Aw%2Fd@10.0.0.5:1080', null], $seen);
    }

    public function test_test_telegram_any_http_answer_is_ok(): void
    {
        Http::fake(['tg.example.com/*' => Http::response('Not Found', 404)]);
        $this->save();

        $this->postJson(self::URL.'/test-telegram')->assertOk()->assertExactJson(['data' => ['ok' => true, 'error' => null]]);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://tg.example.com/' && $request->method() === 'GET');
    }

    public function test_test_telegram_failure_has_no_secrets(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect via socks5h://u:proxy-secret@10.0.0.5:1080 pass proxy-secret'));
        $this->save();

        $response = $this->postJson(self::URL.'/test-telegram')->assertOk()->assertJsonPath('data.ok', false);

        $this->assertStringContainsString('Failed to connect', $response->json('data.error'));
        $this->assertStringNotContainsString('proxy-secret', $response->getContent());
    }

    public function test_channel_error_hides_proxy_password(): void
    {
        Http::fake(fn () => throw new ConnectionException('proxy http://x:proxy-secret@h failed, token 123:SECRET'));
        $this->save();
        $channel = AlertChannel::create(['name' => 'Чат', 'type' => 'telegram', 'config' => ['bot_token' => '123:SECRET', 'chat_id' => '-1001']]);

        $response = $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertJsonPath('data.ok', false);

        $this->assertStringNotContainsString('proxy-secret', $response->getContent());
        $this->assertStringNotContainsString('SECRET', (string) $channel->refresh()->last_error);
    }
}
