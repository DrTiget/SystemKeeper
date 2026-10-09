<?php

namespace Tests\Feature;

use App\Events\BackupFailed;
use App\Events\BackupSucceeded;
use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Events\SslExpiring;
use App\Events\StorageCopyFailed;
use App\Jobs\RunMonitorCheck;
use App\Jobs\SendAlertDelivery;
use App\Models\AlertChannel;
use App\Models\AlertDelivery;
use App\Models\AlertRule;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\DeviceToken;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\PushSubscription;
use App\Models\StorageCopy;
use App\Models\StorageTarget;
use App\Models\User;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\FcmDriver;
use App\Services\Alerts\Senders\AlertMail;
use App\Services\Alerts\WebPushClient;
use App\Services\Monitoring\NetworkProbe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeNetworkProbe;
use Tests\Support\FakeWebPushClient;
use Tests\TestCase;

class AlertDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function rule(string $event, array $channels, array $attrs = []): AlertRule
    {
        $rule = AlertRule::factory()->create(['event' => $event] + $attrs);
        $rule->channels()->sync(collect($channels)->pluck('id'));

        return $rule;
    }

    private function incident(?Monitor $monitor = null): Incident
    {
        $monitor ??= Monitor::factory()->create(['name' => 'Сайт']);

        return Incident::create(['monitor_id' => $monitor->id, 'started_at' => now(), 'reason' => 'HTTP 503']);
    }

    private function runDeliveries(): void
    {
        foreach (AlertDelivery::where('status', 'pending')->pluck('id') as $id) {
            app()->call([new SendAlertDelivery($id), 'handle']);
        }
    }

    public function test_incident_opened_sends_telegram_with_russian_text_and_frontend_link(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['alerts.frontend_url' => 'https://keeper.example.com']);
        $channel = AlertChannel::factory()->create();
        $this->rule('incident.opened', [$channel]);
        $incident = $this->incident();

        event(new IncidentOpened($incident));

        Http::assertSent(fn ($r) => str_contains($r['text'], 'Монитор «Сайт» недоступен')
            && str_contains($r['text'], 'Причина: HTTP 503')
            && str_contains($r['text'], "https://keeper.example.com/monitoring/{$incident->monitor_id}"));
        $delivery = AlertDelivery::first();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);
    }

    public function test_incident_resolved_goes_only_to_resolved_rules(): void
    {
        Mail::fake();
        $channel = AlertChannel::factory()->email()->create();
        $this->rule('incident.opened', [$channel]);
        $this->rule('incident.resolved', [$channel]);
        $incident = $this->incident();
        $incident->update(['resolved_at' => now()->addMinutes(5), 'duration_seconds' => 300]);

        event(new IncidentResolved($incident));

        $this->assertSame(1, AlertDelivery::count());
        Mail::assertSent(AlertMail::class, fn ($m) => str_contains($m->envelope()->subject, 'снова доступен') && $m->alert->body === 'Простой длился 5 мин 0 с.');
    }

    public function test_real_monitoring_flow_triggers_alert(): void
    {
        Queue::fake([SendAlertDelivery::class]);
        $this->app->instance(NetworkProbe::class, new FakeNetworkProbe(reachable: false));
        $channel = AlertChannel::factory()->create();
        $this->rule('incident.opened', [$channel]);
        $monitor = Monitor::factory()->ofType('tcp', 'h:80')->create(['failure_threshold' => 1]);

        RunMonitorCheck::dispatchSync($monitor->id);

        Queue::assertPushed(SendAlertDelivery::class, 1);
        $this->assertSame("monitor:{$monitor->id}", AlertDelivery::first()->object_key);
    }

    public function test_backup_and_storage_copy_events(): void
    {
        Queue::fake();
        $channel = AlertChannel::factory()->create();
        $this->rule('backup.failed', [$channel]);
        $this->rule('backup.succeeded', [$channel]);
        $this->rule('storage_copy.failed', [$channel]);
        $job = BackupJob::factory()->create(['name' => 'Ночной дамп']);
        $failed = Backup::factory()->create(['backup_job_id' => $job->id, 'status' => 'failed', 'error' => 'disk full']);
        $ok = Backup::factory()->create(['backup_job_id' => $job->id, 'status' => 'success', 'size_bytes' => 5 * 1024 * 1024]);
        $target = StorageTarget::factory()->create(['name' => 'Диск-1']);
        $copy = StorageCopy::create(['uploadable_type' => 'backup', 'uploadable_id' => $ok->id, 'storage_target_id' => $target->id, 'remote_path' => '/b/x.gz', 'status' => 'failed', 'error' => 'quota']);

        event(new BackupFailed($failed));
        event(new BackupSucceeded($ok));
        event(new StorageCopyFailed($copy));

        $titles = AlertDelivery::orderBy('id')->pluck('title')->all();
        $this->assertSame(['🔴 Бекап «Ночной дамп» не удался', '🟢 Бекап «Ночной дамп» выполнен', '🔴 Не удалось загрузить копию в хранилище «Диск-1»'], $titles);
        $this->assertStringContainsString('disk full', AlertDelivery::first()->body);
        $this->assertStringContainsString('5 МБ', AlertDelivery::skip(1)->first()->body);
        $this->assertStringContainsString('quota', AlertDelivery::skip(2)->first()->body);
    }

    public function test_ssl_expiring_event_from_monitor_check(): void
    {
        Queue::fake([SendAlertDelivery::class]);
        $probe = new FakeNetworkProbe(sslExpiresAt: CarbonImmutable::now()->addDays(5));
        $this->app->instance(NetworkProbe::class, $probe);
        $this->rule('ssl.expiring', [AlertChannel::factory()->create()]);
        $monitor = Monitor::factory()->ofType('ssl', 'example.com')->create(['ssl_expiry_days' => 14, 'failure_threshold' => 5]);

        RunMonitorCheck::dispatchSync($monitor->id);
        $this->assertSame(1, AlertDelivery::where('event', 'ssl.expiring')->count());
        $this->assertStringContainsString('Осталось дней: 4', AlertDelivery::first()->body);

        // Просроченный сертификат и недоступность — не «скоро истекает»
        $probe->sslExpiresAt = CarbonImmutable::now()->subDay();
        RunMonitorCheck::dispatchSync($monitor->id);
        $this->assertSame(1, AlertDelivery::count());
    }

    public function test_listener_failure_does_not_break_event_dispatch(): void
    {
        $this->rule('ssl.expiring', [AlertChannel::factory()->create()]);
        $this->app->instance(AlertDispatcher::class, new class extends AlertDispatcher
        {
            public function dispatch(AlertMessage $message): array
            {
                throw new \RuntimeException('boom');
            }
        });

        event(new SslExpiring(Monitor::factory()->create(), 3));

        $this->assertSame(0, AlertDelivery::count());
    }

    public function test_rule_filter_by_object(): void
    {
        Queue::fake();
        $channel = AlertChannel::factory()->create();
        $watched = Monitor::factory()->create();
        $other = Monitor::factory()->create();
        $this->rule('incident.opened', [$channel], ['target_type' => 'monitor', 'target_id' => $watched->id]);
        // Фильтр по заданию не подходит инцидентам
        $this->rule('incident.opened', [$channel], ['target_type' => 'backup_job', 'target_id' => 1]);

        event(new IncidentOpened($this->incident($other)));
        $this->assertSame(0, AlertDelivery::count());

        event(new IncidentOpened($this->incident($watched)));
        $this->assertSame(1, AlertDelivery::count());
    }

    public function test_backup_filter_by_server_and_job(): void
    {
        Queue::fake();
        $channel = AlertChannel::factory()->create();
        $job = BackupJob::factory()->create();
        $otherJob = BackupJob::factory()->create();
        $this->rule('backup.failed', [$channel], ['target_type' => 'server', 'target_id' => $job->server_id]);
        $this->rule('backup.failed', [$channel], ['target_type' => 'backup_job', 'target_id' => $otherJob->id]);

        event(new BackupFailed(Backup::factory()->create(['backup_job_id' => $job->id, 'status' => 'failed', 'error' => 'x'])));

        $this->assertSame(1, AlertDelivery::count());
    }

    public function test_disabled_rule_and_disabled_channel_are_ignored(): void
    {
        Queue::fake();
        $on = AlertChannel::factory()->create();
        $off = AlertChannel::factory()->create(['enabled' => false]);
        $this->rule('incident.opened', [$on], ['enabled' => false]);
        $this->rule('incident.opened', [$off]);

        event(new IncidentOpened($this->incident()));

        $this->assertSame(0, AlertDelivery::count());
    }

    public function test_one_rule_fans_out_to_all_channels(): void
    {
        Queue::fake();
        $this->rule('incident.opened', [AlertChannel::factory()->create(), AlertChannel::factory()->email()->create()]);

        event(new IncidentOpened($this->incident()));

        $this->assertSame(2, AlertDelivery::count());
        Queue::assertPushed(SendAlertDelivery::class, 2);
    }

    public function test_quiet_hours_skip_delivery_and_wrap_midnight(): void
    {
        Queue::fake();
        $channel = AlertChannel::factory()->create();
        $this->rule('incident.opened', [$channel], ['quiet_hours_start' => '23:00', 'quiet_hours_end' => '07:00']);

        $this->travelTo(now()->setTime(2, 30));
        event(new IncidentOpened($this->incident()));
        $delivery = AlertDelivery::first();
        $this->assertSame('skipped', $delivery->status);
        $this->assertSame('Тихие часы', $delivery->error);
        Queue::assertNothingPushed();

        $this->travelTo(now()->setTime(12, 0));
        event(new IncidentOpened($this->incident()));
        $this->assertSame(1, AlertDelivery::where('status', 'pending')->count());
        Queue::assertPushed(SendAlertDelivery::class, 1);
    }

    public function test_quiet_hours_same_day_interval(): void
    {
        $rule = AlertRule::factory()->make(['quiet_hours_start' => '13:00', 'quiet_hours_end' => '14:00']);

        $this->travelTo(now()->setTime(13, 30));
        $this->assertTrue($rule->inQuietHours());
        $this->travelTo(now()->setTime(14, 0));
        $this->assertFalse($rule->inQuietHours());
        $this->assertFalse(AlertRule::factory()->make()->inQuietHours());
    }

    public function test_throttle_per_object(): void
    {
        Queue::fake();
        $this->rule('incident.opened', [AlertChannel::factory()->create()], ['throttle_minutes' => 10]);
        $a = Monitor::factory()->create();
        $b = Monitor::factory()->create();

        event(new IncidentOpened($this->incident($a)));
        event(new IncidentOpened($this->incident($a)));
        event(new IncidentOpened($this->incident($b)));

        $this->assertSame(2, AlertDelivery::where('status', 'pending')->count());
        $throttled = AlertDelivery::where('status', 'skipped')->first();
        $this->assertSame("monitor:{$a->id}", $throttled->object_key);
        $this->assertStringContainsString('Антиспам', $throttled->error);

        $this->travel(11)->minutes();
        event(new IncidentOpened($this->incident($a)));
        $this->assertSame(3, AlertDelivery::where('status', 'pending')->count());
    }

    public function test_throttle_zero_disables_antispam(): void
    {
        Queue::fake();
        $this->rule('incident.opened', [AlertChannel::factory()->create()], ['throttle_minutes' => 0]);
        $monitor = Monitor::factory()->create();

        event(new IncidentOpened($this->incident($monitor)));
        event(new IncidentOpened($this->incident($monitor)));

        $this->assertSame(2, AlertDelivery::where('status', 'pending')->count());
    }

    public function test_throttled_deliveries_do_not_extend_the_window(): void
    {
        Queue::fake();
        $this->rule('incident.opened', [AlertChannel::factory()->create()], ['throttle_minutes' => 10]);
        $monitor = Monitor::factory()->create();

        event(new IncidentOpened($this->incident($monitor)));
        $this->travel(6)->minutes();
        event(new IncidentOpened($this->incident($monitor)));
        $this->travel(5)->minutes();
        event(new IncidentOpened($this->incident($monitor)));

        $this->assertSame(2, AlertDelivery::where('status', 'pending')->count());
    }

    public function test_failed_send_keeps_error_and_rethrows_for_retry(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden'], 403)]);
        Queue::fake();
        $this->rule('incident.opened', [AlertChannel::factory()->create()]);
        event(new IncidentOpened($this->incident()));
        $delivery = AlertDelivery::first();

        try {
            app()->call([new SendAlertDelivery($delivery->id), 'handle']);
            $this->fail('Ожидалось исключение для повтора');
        } catch (\RuntimeException $e) {
            $this->assertSame('Telegram: Forbidden', $e->getMessage());
        }

        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('Telegram: Forbidden', $delivery->error);

        // Ретраи исчерпаны
        (new SendAlertDelivery($delivery->id))->failed(new \RuntimeException('Telegram: Forbidden'));
        $this->assertSame('failed', $delivery->fresh()->status);
    }

    public function test_job_has_retries_and_backoff(): void
    {
        $job = new SendAlertDelivery(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120], $job->backoff());
    }

    public function test_sent_delivery_is_not_sent_twice(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->rule('incident.opened', [AlertChannel::factory()->create()]);
        event(new IncidentOpened($this->incident()));

        $id = AlertDelivery::first()->id;
        app()->call([new SendAlertDelivery($id), 'handle']);

        Http::assertSentCount(1);
    }

    public function test_channel_disabled_after_queueing_is_skipped(): void
    {
        Queue::fake();
        $channel = AlertChannel::factory()->create();
        $this->rule('incident.opened', [$channel]);
        event(new IncidentOpened($this->incident()));
        $channel->update(['enabled' => false]);

        $this->runDeliveries();

        $this->assertSame('skipped', AlertDelivery::first()->status);
    }

    public function test_webpush_sends_to_all_subscriptions_and_drops_expired(): void
    {
        Queue::fake();
        $push = new FakeWebPushClient;
        $this->app->instance(WebPushClient::class, $push);
        $user = User::factory()->create();
        foreach (['live', 'dead'] as $name) {
            PushSubscription::create(['user_id' => $user->id, 'endpoint' => "https://push.example.com/{$name}", 'endpoint_hash' => PushSubscription::hash("https://push.example.com/{$name}"), 'p256dh' => 'k', 'auth' => 'a']);
        }
        $push->expired = ['https://push.example.com/dead'];
        $this->rule('incident.opened', [AlertChannel::factory()->ofType('webpush')->create()]);
        event(new IncidentOpened($this->incident()));

        $this->runDeliveries();

        $this->assertSame('sent', AlertDelivery::first()->status);
        $this->assertCount(1, $push->sent);
        $this->assertSame('https://push.example.com/live', $push->sent[0]['endpoint']);
        $this->assertSame('🔴 Монитор «Сайт» недоступен', $push->sent[0]['payload']['title']);
        $this->assertStringContainsString('/monitoring/', $push->sent[0]['payload']['url']);
        $this->assertSame(['https://push.example.com/live'], PushSubscription::pluck('endpoint')->all());
    }

    public function test_webpush_total_failure_is_retried_and_no_subscribers_is_skipped(): void
    {
        Queue::fake();
        $push = new FakeWebPushClient;
        $this->app->instance(WebPushClient::class, $push);
        $this->rule('incident.opened', [AlertChannel::factory()->ofType('webpush')->create()]);
        event(new IncidentOpened($this->incident()));
        $this->runDeliveries();
        $this->assertSame('skipped', AlertDelivery::first()->status);
        $this->assertSame('Нет подписанных браузеров', AlertDelivery::first()->error);

        PushSubscription::create(['user_id' => User::factory()->create()->id, 'endpoint' => 'https://push.example.com/a', 'endpoint_hash' => PushSubscription::hash('https://push.example.com/a'), 'p256dh' => 'k', 'auth' => 'a']);
        $push->fail = true;
        $delivery = AlertDelivery::first();
        $delivery->update(['status' => 'pending']);

        $this->expectExceptionMessage('push service unavailable');
        app()->call([new SendAlertDelivery($delivery->id), 'handle']);
    }

    public function test_mobile_channel_is_skipped_while_fcm_disabled(): void
    {
        Queue::fake();
        $this->rule('incident.opened', [AlertChannel::factory()->ofType('mobile')->create()]);
        event(new IncidentOpened($this->incident()));

        $this->runDeliveries();

        $delivery = AlertDelivery::first();
        $this->assertSame('skipped', $delivery->status);
        $this->assertStringContainsString('FCM', $delivery->error);
    }

    public function test_mobile_channel_uses_fcm_driver_when_enabled(): void
    {
        Queue::fake();
        $fcm = new class implements FcmDriver
        {
            public array $calls = [];

            public function enabled(): bool
            {
                return true;
            }

            public function send(array $tokens, string $title, string $body, array $data = []): void
            {
                $this->calls[] = compact('tokens', 'title', 'data');
            }
        };
        $this->app->instance(FcmDriver::class, $fcm);
        DeviceToken::create(['user_id' => User::factory()->create()->id, 'platform' => 'android', 'token' => 'tok', 'token_hash' => DeviceToken::hash('tok')]);
        $this->rule('incident.opened', [AlertChannel::factory()->ofType('mobile')->create()]);
        event(new IncidentOpened($this->incident()));

        $this->runDeliveries();

        $this->assertSame('sent', AlertDelivery::first()->status);
        $this->assertSame(['tok'], $fcm->calls[0]['tokens']);
    }

    public function test_deliveries_api_and_prune(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $channel = AlertChannel::factory()->create();
        $old = AlertDelivery::create(['alert_channel_id' => $channel->id, 'event' => 'incident.opened', 'title' => 't', 'body' => 'b', 'status' => 'sent']);
        $old->forceFill(['created_at' => now()->subDays(100)])->save();
        AlertDelivery::create(['alert_channel_id' => $channel->id, 'event' => 'backup.failed', 'title' => 't', 'body' => 'b', 'status' => 'failed', 'error' => 'x']);

        $this->getJson('/api/v1/alert-deliveries')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'failed');
        $this->getJson('/api/v1/alert-deliveries?status=sent')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/alert-deliveries?alert_channel_id={$channel->id}&event=backup.failed")->assertOk()->assertJsonCount(1, 'data');

        $this->artisan('alerts:prune')->assertSuccessful();
        $this->assertSame(1, AlertDelivery::count());
    }

    public function test_dispatcher_returns_deliveries_for_message_without_object(): void
    {
        Queue::fake();
        $this->rule('metric.threshold_exceeded', [AlertChannel::factory()->create()]);

        $deliveries = app(AlertDispatcher::class)->dispatch(new AlertMessage('metric.threshold_exceeded', 'CPU', 'Загрузка 95%'));

        $this->assertCount(1, $deliveries);
    }
}
