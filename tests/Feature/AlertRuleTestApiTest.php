<?php

namespace Tests\Feature;

use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\AlertChannel;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Services\Alerts\AlertKinds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /alert-rules/{id}/test: пример уведомления по правилу во все каналы правила.
 */
class AlertRuleTestApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        config(['mail.default' => 'smtp', 'app.url' => 'https://sk.example.org']);
    }

    private function telegram(string $name = 'Ops'): AlertChannel
    {
        return AlertChannel::create(['name' => $name, 'type' => 'telegram', 'config' => ['bot_token' => '123:SECRET', 'chat_id' => '-1001']]);
    }

    private function email(): AlertChannel
    {
        return AlertChannel::create(['name' => 'Почта', 'type' => 'email', 'config' => ['recipients' => ['ops@example.com']]]);
    }

    private function webhook(): AlertChannel
    {
        return AlertChannel::create(['name' => 'Хук', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/x', 'secret' => 'top-secret']]);
    }

    /** @param list<AlertChannel> $channels */
    private function rule(array $channels, array $attrs = []): AlertRule
    {
        $rule = AlertRule::create($attrs + ['name' => 'CPU высокий', 'kind' => 'server_cpu', 'severity' => 'critical', 'threshold' => 90, 'duration' => 5]);
        $rule->channels()->sync(collect($channels)->pluck('id'));

        return $rule;
    }

    public function test_requires_auth(): void
    {
        $rule = $this->rule([$this->telegram()]);
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => ''])->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertUnauthorized();
    }

    public function test_sends_sample_to_every_channel_and_reports_each(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true]), 'hooks.example.com/*' => Http::response('ok')]);
        Mail::fake();
        $project = Project::factory()->create(['name' => 'Магазин']);
        Server::factory()->create(['name' => 'web-1', 'project_id' => $project->id]);
        $channels = [$telegram = $this->telegram(), $email = $this->email(), $hook = $this->webhook()];
        $rule = $this->rule($channels, ['project_id' => $project->id]);

        $this->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertOk()->assertExactJson(['data' => ['results' => [
            ['channel' => ['id' => $telegram->id, 'name' => 'Ops', 'type' => 'telegram'], 'ok' => true, 'error' => null],
            ['channel' => ['id' => $email->id, 'name' => 'Почта', 'type' => 'email'], 'ok' => true, 'error' => null],
            ['channel' => ['id' => $hook->id, 'name' => 'Хук', 'type' => 'webhook'], 'ok' => true, 'error' => null],
        ]]]);

        $link = "https://sk.example.org/alerts/rules/{$rule->id}";
        // Telegram: HTML, заголовок, объект и проект из области правила, условие в сообщении, ссылка на правило
        Http::assertSent(function (Request $r) use ($link) {
            return str_contains($r->url(), '/bot123:SECRET/sendMessage')
                && $r['parse_mode'] === 'HTML'
                && $r['disable_web_page_preview'] === true
                && str_contains($r['text'], '🧪 <b>Тестовое уведомление</b>')
                && str_contains($r['text'], 'CPU 95% дольше 5 мин')
                && str_contains($r['text'], 'Объект: Сервер «web-1»')
                && str_contains($r['text'], 'Проект: Магазин')
                && str_contains($r['text'], 'Правило: <a href="'.$link.'">CPU высокий</a>')
                && str_contains($r['text'], '<a href="'.$link.'">Открыть в SystemKeeper</a>');
        });
        // письмо: тема, HTML и текстовая версия
        Mail::assertSent(AlertMail::class, function (AlertMail $mail) use ($link) {
            $mail->assertHasSubject('[ТЕСТ] Сервер web-1: CPU 95% дольше 5 мин');
            foreach (['assertSeeInHtml', 'assertSeeInText'] as $assert) {
                $mail->$assert('Тестовое уведомление');
                $mail->$assert('CPU 95% дольше 5 мин');
                $mail->$assert('CPU выше 90% дольше 5 мин');
                $mail->$assert('web-1');
                $mail->$assert($link);
            }
            $mail->assertSeeInHtml('Открыть в SystemKeeper');

            return $mail->hasTo('ops@example.com');
        });
        // вебхук: прежние поля и новые text / markdown
        Http::assertSent(function (Request $r) use ($link) {
            return $r->url() === 'https://hooks.example.com/x'
                && $r->header('X-Signature') === [hash_hmac('sha256', $r->body(), 'top-secret')]
                && $r['event'] === 'test'
                && $r['alert']['rule'] === ['id' => $r['alert']['rule']['id'], 'name' => 'CPU высокий', 'kind' => 'server_cpu']
                && $r['alert']['object']['name'] === 'web-1'
                && str_contains($r['title'], 'Тестовое уведомление')
                && str_contains($r['text'], 'Тестовое уведомление') && str_contains($r['text'], $link)
                && str_contains($r['markdown'], '**🧪 Тестовое уведомление**') && str_contains($r['markdown'], "({$link})")
                && $r['url'] === $link;
        });
    }

    public function test_does_not_create_alerts_or_touch_rule(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $rule = $this->rule([$this->telegram()]);
        $this->assertNull($rule->last_fired_at);

        $this->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertOk();

        $this->assertSame(0, Alert::count());
        $this->assertNull($rule->refresh()->last_fired_at);
        $this->assertSame(0, $rule->pendings()->count());
        // а у канала отмечена отправка
        $this->assertNotNull(AlertChannel::first()->last_sent_at);
    }

    public function test_uses_sample_object_when_scope_is_empty(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $rule = $this->rule([$this->telegram()], ['kind' => 'monitor_ssl', 'severity' => 'warning', 'threshold' => 14, 'duration' => 0]);

        $this->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertOk()->assertJsonPath('data.results.0.ok', true);

        Http::assertSent(fn (Request $r) => str_starts_with($r['text'], '🧪 <b>Тестовое уведомление</b>')
            && str_contains($r['text'], 'Объект: Монитор «Пример объекта»')
            && str_contains($r['text'], 'Сертификат Пример объекта истекает через 7 дн.'));
    }

    public function test_every_kind_produces_a_sample(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $channel = $this->telegram();

        foreach (AlertKinds::all() as $kind) {
            $threshold = AlertKinds::threshold($kind) ? 50 : null;
            $rule = $this->rule([$channel], ['name' => $kind, 'kind' => $kind, 'threshold' => $threshold]);

            $this->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertOk()->assertJsonPath('data.results.0.ok', true);
        }

        Http::assertSentCount(count(AlertKinds::all()));
    }

    public function test_channel_errors_are_reported_without_secrets(): void
    {
        Http::fake([
            'api.telegram.org/*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host for https://api.telegram.org/bot123:SECRET/sendMessage'),
            'hooks.example.com/*' => Http::response('boom', 502),
        ]);
        Mail::fake();
        config(['mail.default' => 'log']);
        $rule = $this->rule([$this->telegram(), $this->email(), $this->webhook()]);

        $response = $this->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertOk();

        $response->assertJsonPath('data.results.0.ok', false)
            ->assertJsonPath('data.results.1.ok', false)->assertJsonPath('data.results.1.error', 'Почта не настроена: Настройки → Уведомления')
            ->assertJsonPath('data.results.2.ok', false)->assertJsonPath('data.results.2.error', 'Вебхук ответил кодом 502');
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertStringNotContainsString('top-secret', $response->getContent());
        $this->assertNotNull(AlertChannel::where('type', 'webhook')->first()->last_error);
    }

    public function test_one_failed_channel_does_not_block_others(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400), 'hooks.example.com/*' => Http::response('ok')]);
        $rule = $this->rule([$this->telegram(), $this->webhook()]);

        $this->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertOk()
            ->assertJsonPath('data.results.0.error', 'Telegram: chat not found')
            ->assertJsonPath('data.results.1.ok', true);
    }

    public function test_disabled_channel_still_gets_the_test(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('ok')]);
        $hook = $this->webhook();
        $hook->update(['enabled' => false]);
        $rule = $this->rule([$hook]);

        $this->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertOk()->assertJsonPath('data.results.0.ok', true);
    }

    public function test_rule_without_channels_is_422(): void
    {
        Http::fake();
        $rule = $this->rule([]);

        $this->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertUnprocessable()->assertJsonValidationErrors('channel_ids');
        Http::assertNothingSent();
    }

    public function test_unknown_rule_is_404(): void
    {
        $this->postJson('/api/v1/alert-rules/999/test')->assertNotFound();
    }

    public function test_is_limited_to_ten_per_minute(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $rule = $this->rule([$this->telegram()]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertOk();
        }
        $this->postJson("/api/v1/alert-rules/{$rule->id}/test")->assertTooManyRequests();
    }
}
