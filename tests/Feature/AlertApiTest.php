<?php

namespace Tests\Feature;

use App\Models\AlertChannel;
use App\Models\AlertRule;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Alerts\FcmDriver;
use App\Services\Alerts\Senders\AlertMail;
use App\Services\Alerts\WebPushClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeWebPushClient;
use Tests\TestCase;

class AlertApiTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_requires_authentication(): void
    {
        $channel = AlertChannel::factory()->create();
        $rule = AlertRule::factory()->create();

        $this->getJson('/api/v1/alert-channels')->assertUnauthorized();
        $this->postJson('/api/v1/alert-channels', [])->assertUnauthorized();
        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertUnauthorized();
        $this->getJson('/api/v1/alert-rules')->assertUnauthorized();
        $this->deleteJson("/api/v1/alert-rules/{$rule->id}")->assertUnauthorized();
        $this->getJson('/api/v1/alert-deliveries')->assertUnauthorized();
        $this->getJson('/api/v1/push/vapid-public-key')->assertUnauthorized();
        $this->postJson('/api/v1/push-subscriptions', [])->assertUnauthorized();
        $this->deleteJson('/api/v1/push-subscriptions', [])->assertUnauthorized();
        $this->postJson('/api/v1/device-tokens', [])->assertUnauthorized();
    }

    public function test_creates_telegram_channel_and_hides_token(): void
    {
        $this->login();

        $response = $this->postJson('/api/v1/alert-channels', [
            'name' => 'Дежурный', 'type' => 'telegram', 'settings' => ['bot_token' => '123:ABC-def', 'chat_id' => -100500],
        ])->assertCreated()
            ->assertJsonPath('data.settings.chat_id', '-100500')
            ->assertJsonPath('data.settings.has_bot_token', true)
            ->assertJsonPath('data.enabled', true);

        $this->assertStringNotContainsString('ABC-def', $response->getContent());
        $this->getJson('/api/v1/alert-channels')->assertOk();
        $this->assertStringNotContainsString('ABC-def', $this->getJson('/api/v1/alert-channels')->getContent());

        // В БД секрет зашифрован
        $raw = \DB::table('alert_channels')->value('settings');
        $this->assertStringNotContainsString('ABC-def', $raw);
        $this->assertSame('123:ABC-def', AlertChannel::first()->setting('bot_token'));
    }

    public function test_validates_channel_settings_by_type(): void
    {
        $this->login();

        $this->postJson('/api/v1/alert-channels', ['name' => 'a', 'type' => 'telegram', 'settings' => ['chat_id' => '1']])->assertUnprocessable()->assertJsonValidationErrors('settings.bot_token');
        $this->postJson('/api/v1/alert-channels', ['name' => 'a', 'type' => 'telegram', 'settings' => ['bot_token' => 'bad', 'chat_id' => '1']])->assertUnprocessable()->assertJsonValidationErrors('settings.bot_token');
        $this->postJson('/api/v1/alert-channels', ['name' => 'a', 'type' => 'email', 'settings' => ['recipients' => ['nope']]])->assertUnprocessable()->assertJsonValidationErrors('settings.recipients.0');
        $this->postJson('/api/v1/alert-channels', ['name' => 'a', 'type' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('settings.recipients');
        $this->postJson('/api/v1/alert-channels', ['name' => 'a', 'type' => 'webpush', 'settings' => ['chat_id' => '1']])->assertUnprocessable()->assertJsonValidationErrors('settings.chat_id');
        $this->postJson('/api/v1/alert-channels', ['name' => 'a', 'type' => 'sms'])->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->postJson('/api/v1/alert-channels', ['name' => 'w', 'type' => 'webpush'])->assertCreated();
        $this->postJson('/api/v1/alert-channels', ['name' => 'm', 'type' => 'mobile'])->assertCreated();
        $this->postJson('/api/v1/alert-channels', ['name' => 'e', 'type' => 'email', 'settings' => ['recipients' => ['a@example.com']]])->assertCreated()->assertJsonPath('data.settings.recipients.0', 'a@example.com');
    }

    public function test_update_keeps_secret_and_cannot_change_type(): void
    {
        $this->login();
        $channel = AlertChannel::factory()->create();

        $this->patchJson("/api/v1/alert-channels/{$channel->id}", ['type' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->patchJson("/api/v1/alert-channels/{$channel->id}", ['name' => 'Новое', 'enabled' => false, 'settings' => ['chat_id' => '777']])
            ->assertOk()->assertJsonPath('data.name', 'Новое')->assertJsonPath('data.enabled', false)->assertJsonPath('data.settings.chat_id', '777');

        $channel->refresh();
        $this->assertSame('123456:SECRET-TOKEN', $channel->setting('bot_token'));
        $this->deleteJson("/api/v1/alert-channels/{$channel->id}")->assertNoContent();
    }

    public function test_telegram_test_message(): void
    {
        $this->login();
        $channel = AlertChannel::factory()->create();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertJsonPath('data.ok', true);

        Http::assertSent(fn ($r) => $r->url() === 'https://api.telegram.org/bot123456:SECRET-TOKEN/sendMessage'
            && $r['chat_id'] === '-100500' && str_contains($r['text'], 'Тест SystemKeeper'));
    }

    public function test_telegram_error_is_reported_without_leaking_token(): void
    {
        $this->login();
        $channel = AlertChannel::factory()->create();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

        $response = $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()
            ->assertJsonPath('data.ok', false)->assertJsonPath('data.error', 'Telegram: Bad Request: chat not found');

        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot123456:SECRET-TOKEN/sendMessage'));
        $response = $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertJsonPath('data.ok', false);
        $this->assertStringNotContainsString('SECRET-TOKEN', $response->getContent());
    }

    public function test_email_test_message(): void
    {
        $this->login();
        Mail::fake();
        $channel = AlertChannel::factory()->email(['a@example.com', 'b@example.com'])->create();

        $this->postJson("/api/v1/alert-channels/{$channel->id}/test")->assertOk()->assertJsonPath('data.ok', true);

        Mail::assertSent(AlertMail::class, fn ($m) => $m->hasTo('a@example.com') && $m->hasTo('b@example.com') && str_contains($m->envelope()->subject, 'Тест'));
    }

    public function test_webpush_and_mobile_channels_report_skipped_when_nothing_to_send(): void
    {
        $this->login();
        $this->app->instance(WebPushClient::class, new FakeWebPushClient);
        $web = AlertChannel::factory()->ofType('webpush')->create();
        $mobile = AlertChannel::factory()->ofType('mobile')->create();

        $this->postJson("/api/v1/alert-channels/{$web->id}/test")->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.skipped', true);
        $this->postJson("/api/v1/alert-channels/{$mobile->id}/test")->assertOk()->assertJsonPath('data.skipped', true)
            ->assertJsonPath('data.error', 'Отправка в мобильное приложение (FCM) отключена');
        $this->assertFalse(app(FcmDriver::class)->enabled());
    }

    public function test_creates_rule_with_channels_and_filter(): void
    {
        $this->login();
        $monitor = Monitor::factory()->create();
        $a = AlertChannel::factory()->create();
        $b = AlertChannel::factory()->email()->create();

        $this->postJson('/api/v1/alert-rules', [
            'name' => 'Падения сайта', 'event' => 'incident.opened', 'target_type' => 'monitor', 'target_id' => $monitor->id,
            'channel_ids' => [$a->id, $b->id], 'quiet_hours_start' => '23:00', 'quiet_hours_end' => '07:00', 'throttle_minutes' => 30,
        ])->assertCreated()
            ->assertJsonPath('data.target_type', 'monitor')
            ->assertJsonPath('data.target_id', $monitor->id)
            ->assertJsonCount(2, 'data.channels')
            ->assertJsonPath('data.throttle_minutes', 30)
            ->assertJsonPath('data.quiet_hours_start', '23:00');

        $this->postJson('/api/v1/alert-rules', ['name' => 'Все', 'event' => 'backup.failed', 'channel_ids' => [$a->id]])
            ->assertCreated()->assertJsonPath('data.target_type', null)->assertJsonPath('data.throttle_minutes', 15);
    }

    public function test_validates_rule(): void
    {
        $this->login();
        $channel = AlertChannel::factory()->create();
        $base = ['name' => 'r', 'event' => 'incident.opened', 'channel_ids' => [$channel->id]];

        $this->postJson('/api/v1/alert-rules', ['event' => 'boom'] + $base)->assertUnprocessable()->assertJsonValidationErrors('event');
        $this->postJson('/api/v1/alert-rules', ['channel_ids' => []] + $base)->assertUnprocessable()->assertJsonValidationErrors('channel_ids');
        $this->postJson('/api/v1/alert-rules', ['channel_ids' => [999]] + $base)->assertUnprocessable()->assertJsonValidationErrors('channel_ids.0');
        $this->postJson('/api/v1/alert-rules', ['target_type' => 'monitor'] + $base)->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->postJson('/api/v1/alert-rules', ['target_type' => 'monitor', 'target_id' => 999] + $base)->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->postJson('/api/v1/alert-rules', ['target_id' => 1] + $base)->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->postJson('/api/v1/alert-rules', ['quiet_hours_start' => '22:00'] + $base)->assertUnprocessable()->assertJsonValidationErrors('quiet_hours_end');
        $this->postJson('/api/v1/alert-rules', ['quiet_hours_start' => '25:00', 'quiet_hours_end' => '07:00'] + $base)->assertUnprocessable()->assertJsonValidationErrors('quiet_hours_start');
        $this->postJson('/api/v1/alert-rules', ['throttle_minutes' => -1] + $base)->assertUnprocessable()->assertJsonValidationErrors('throttle_minutes');
    }

    public function test_updates_and_deletes_rule(): void
    {
        $this->login();
        $a = AlertChannel::factory()->create();
        $b = AlertChannel::factory()->email()->create();
        $monitor = Monitor::factory()->create();
        $rule = AlertRule::factory()->create(['target_type' => 'monitor', 'target_id' => $monitor->id]);
        $rule->channels()->sync([$a->id]);

        $this->patchJson("/api/v1/alert-rules/{$rule->id}", ['channel_ids' => [$b->id], 'enabled' => false, 'target_type' => null])
            ->assertOk()->assertJsonPath('data.channel_ids', [$b->id])->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.target_type', null)->assertJsonPath('data.target_id', null);

        $this->getJson('/api/v1/alert-rules?event=backup.failed')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/alert-rules?event=incident.opened')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/alert-rules/{$rule->id}")->assertNoContent();
        $this->assertDatabaseCount('alert_channel_alert_rule', 0);
    }
}
