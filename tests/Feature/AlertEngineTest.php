<?php

namespace Tests\Feature;

use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\AlertChannel;
use App\Models\AlertNotification;
use App\Models\AlertPending;
use App\Models\AlertRule;
use App\Models\Backup;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerBackupSettings;
use App\Models\ServerMetrics;
use App\Services\Alerts\AlertEngine;
use App\Services\Alerts\ConditionEvaluator;
use App\Services\System\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Движок правил: duration, повторы, закрытие, взятие в работу, область и targets, каждый вид правила.
 * Очередь синхронная, вебхуки — Http::fake, поэтому уведомления видны сразу.
 */
class AlertEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfMinute());
        Http::fake(['hook.test/*' => Http::response('ok')]);
        config(['mail.default' => 'smtp']);
    }

    private function channel(string $name = 'Вебхук', bool $enabled = true): AlertChannel
    {
        return AlertChannel::create(['name' => $name, 'type' => 'webhook', 'enabled' => $enabled, 'config' => ['url' => 'https://hook.test/'.str_replace(' ', '', $name), 'secret' => 's']]);
    }

    /** @param list<AlertChannel> $channels */
    private function rule(array $attrs = [], array $channels = []): AlertRule
    {
        $rule = AlertRule::create($attrs + ['name' => 'Правило', 'kind' => 'server_cpu', 'severity' => 'warning', 'threshold' => 90, 'duration' => 0]);
        $rule->channels()->sync(collect($channels)->pluck('id'));

        return $rule;
    }

    /** Сервер со свежим снимком метрик */
    private function server(array $snapshot = [], array $attrs = []): Server
    {
        $server = Server::factory()->create($attrs);
        $this->metrics($server, $snapshot);

        return $server;
    }

    private function metrics(Server $server, array $snapshot = [], array $attrs = []): ServerMetrics
    {
        return ServerMetrics::updateOrCreate(['server_id' => $server->id], $attrs + [
            'collected_at' => now(), 'error' => null,
            'snapshot' => $snapshot + ['cpu' => 10.0, 'cpu_cores' => 4, 'load' => [0.1, 0.1, 0.1], 'memory' => ['used' => 1, 'total' => 10], 'swap' => null, 'disks' => [], 'network' => null, 'uptime' => 1, 'os' => null],
        ]);
    }

    private function run_(): array
    {
        return $this->app->make(AlertEngine::class)->run();
    }

    // --- жизненный цикл

    public function test_fires_at_once_with_zero_duration_and_notifies_all_enabled_channels(): void
    {
        $a = $this->channel('Первый');
        $b = $this->channel('Второй');
        $off = $this->channel('Выключенный', enabled: false);
        $rule = $this->rule(['severity' => 'critical'], [$a, $b, $off]);
        $server = $this->server(['cpu' => 97.0], ['name' => 'web-1']);

        $this->assertSame(['fired' => 1, 'repeated' => 0, 'resolved' => 0], $this->run_());

        $alert = Alert::sole();
        $this->assertSame(Alert::FIRING, $alert->status);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('CPU 97%', $alert->message);
        $this->assertSame(['server', $server->id, 'web-1'], [$alert->object_type, $alert->object_id, $alert->object_name]);
        $this->assertEquals(now(), $alert->started_at);
        $this->assertEquals(now(), $rule->refresh()->last_fired_at);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], AlertNotification::pluck('alert_channel_id')->all());
        $this->assertSame(['firing'], AlertNotification::pluck('event')->unique()->values()->all());
        $this->assertSame([true], AlertNotification::pluck('ok')->unique()->values()->all());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r['event'] === 'firing' && $r['alert']['message'] === 'CPU 97%' && $r['alert']['object']['name'] === 'web-1' && $r->hasHeader('X-Signature'));
        $this->assertNotNull($a->refresh()->last_sent_at);
    }

    public function test_duration_must_hold_before_firing(): void
    {
        $this->rule(['duration' => 5], [$this->channel()]);
        $server = $this->server(['cpu' => 97.0]);

        $this->run_();
        $this->assertSame(0, Alert::count());
        $this->assertSame(1, AlertPending::count());

        foreach ([1, 2, 3, 4] as $minute) {
            $this->travel(1)->minute();
            $this->metrics($server, ['cpu' => 97.0]);
            $this->run_();
            $this->assertSame(0, Alert::count(), "через {$minute} мин");
        }

        $this->travel(1)->minute();
        $this->metrics($server, ['cpu' => 97.0]);
        $this->assertSame(1, $this->run_()['fired']);
        $this->assertSame('CPU 97% дольше 5 мин', Alert::sole()->message);
        Http::assertSentCount(1);
    }

    public function test_duration_restarts_when_condition_drops(): void
    {
        $this->rule(['duration' => 3]);
        $server = $this->server(['cpu' => 97.0]);

        $this->run_();
        $this->travel(2)->minutes();
        $this->metrics($server, ['cpu' => 50.0]);
        $this->run_();
        $this->assertSame(0, AlertPending::count());

        // снова выше порога: отсчёт заново, ещё 2 минуты — рано
        $this->travel(1)->minute();
        $this->metrics($server, ['cpu' => 97.0]);
        $this->run_();
        $this->travel(2)->minutes();
        $this->metrics($server, ['cpu' => 97.0]);
        $this->run_();
        $this->assertSame(0, Alert::count());

        $this->travel(1)->minute();
        $this->metrics($server, ['cpu' => 97.0]);
        $this->run_();
        $this->assertSame(1, Alert::count());
    }

    public function test_one_active_alert_per_rule_and_object(): void
    {
        $this->rule([], [$this->channel()]);
        $server = $this->server(['cpu' => 97.0]);

        $this->run_();
        $this->travel(1)->minute();
        $this->metrics($server, ['cpu' => 98.0]);
        $stats = $this->run_();

        $this->assertSame(['fired' => 0, 'repeated' => 0, 'resolved' => 0], $stats);
        $alert = Alert::sole();
        // значения в активном алерте обновляются, уведомление не дублируется
        $this->assertSame('CPU 98%', $alert->message);
        $this->assertSame(1, AlertNotification::count());
    }

    public function test_resolves_and_notifies_when_enabled(): void
    {
        $this->rule([], [$this->channel()]);
        $server = $this->server(['cpu' => 97.0]);
        $this->run_();

        $this->travel(7)->minutes();
        $this->metrics($server, ['cpu' => 20.0]);
        $this->assertSame(1, $this->run_()['resolved']);

        $alert = Alert::sole();
        $this->assertSame(Alert::RESOLVED, $alert->status);
        $this->assertEquals(now(), $alert->resolved_at);
        $this->assertSame(['firing', 'resolved'], AlertNotification::orderBy('id')->pluck('event')->all());
        Http::assertSent(fn (Request $r) => $r['event'] === 'resolved' && str_contains($r['title'], 'Решено') && $r['alert']['status'] === 'resolved' && str_contains($r['text'], 'Длительность: 7 мин') && str_contains($r['markdown'], '**Длительность:** 7 мин'));
        $this->assertSame(0, AlertPending::count());
    }

    public function test_no_resolved_notification_when_disabled_in_rule(): void
    {
        $this->rule(['notify_resolved' => false], [$this->channel()]);
        $server = $this->server(['cpu' => 97.0]);
        $this->run_();

        $this->travel(1)->minute();
        $this->metrics($server, ['cpu' => 20.0]);
        $this->run_();

        $this->assertSame(Alert::RESOLVED, Alert::sole()->status);
        $this->assertSame(1, AlertNotification::count());
    }

    public function test_new_alert_after_resolve(): void
    {
        $this->rule();
        $server = $this->server(['cpu' => 97.0]);
        $this->run_();
        $this->metrics($server, ['cpu' => 10.0]);
        $this->run_();
        $this->metrics($server, ['cpu' => 99.0]);
        $this->run_();

        $this->assertSame([Alert::RESOLVED, Alert::FIRING], Alert::orderBy('id')->pluck('status')->all());
    }

    public function test_repeat_every_until_acknowledged(): void
    {
        $this->rule(['repeat_every' => 30], [$this->channel()]);
        $server = $this->server(['cpu' => 97.0]);
        $this->run_();

        $this->travel(29)->minutes();
        $this->metrics($server, ['cpu' => 97.0]);
        $this->assertSame(0, $this->run_()['repeated']);

        $this->travel(1)->minute();
        $this->metrics($server, ['cpu' => 97.0]);
        $this->assertSame(1, $this->run_()['repeated']);
        $this->assertSame(['firing', 'repeat'], AlertNotification::orderBy('id')->pluck('event')->all());

        // следующее напоминание — ещё через 30 минут от предыдущего
        $this->travel(30)->minutes();
        $this->metrics($server, ['cpu' => 97.0]);
        $this->assertSame(1, $this->run_()['repeated']);

        // взяли в работу — больше не напоминаем
        Alert::sole()->update(['acknowledged_at' => now()]);
        $this->travel(60)->minutes();
        $this->metrics($server, ['cpu' => 97.0]);
        $this->assertSame(0, $this->run_()['repeated']);
        $this->assertSame(3, AlertNotification::count());

        // решение уведомляем и для алерта, взятого в работу
        $this->metrics($server, ['cpu' => 10.0]);
        $this->run_();
        $this->assertSame('resolved', AlertNotification::orderByDesc('id')->value('event'));
    }

    public function test_no_repeat_without_repeat_every(): void
    {
        $this->rule([], [$this->channel()]);
        $server = $this->server(['cpu' => 97.0]);
        $this->run_();

        $this->travel(600)->minutes();
        $this->metrics($server, ['cpu' => 97.0]);
        $this->assertSame(0, $this->run_()['repeated']);
    }

    public function test_failing_channel_is_recorded_and_does_not_stop_others(): void
    {
        Http::fake(['bad.test/*' => Http::response('boom', 500), 'hook.test/*' => Http::response('ok')]);
        $bad = AlertChannel::create(['name' => 'Плохой', 'type' => 'webhook', 'config' => ['url' => 'https://bad.test/x']]);
        $good = $this->channel('Хороший');
        $this->rule([], [$bad, $good]);
        $this->server(['cpu' => 97.0]);

        $this->run_();

        $this->assertSame(1, Alert::count());
        $failed = AlertNotification::where('alert_channel_id', $bad->id)->sole();
        $this->assertFalse($failed->ok);
        $this->assertSame('Вебхук ответил кодом 500', $failed->error);
        $this->assertSame('Вебхук ответил кодом 500', $bad->refresh()->last_error);
        $this->assertTrue(AlertNotification::where('alert_channel_id', $good->id)->sole()->ok);
        $this->assertNull($good->refresh()->last_error);
    }

    public function test_broken_rule_does_not_stop_the_cycle(): void
    {
        $broken = $this->rule(['name' => 'сломанное']);
        $this->rule(['name' => 'рабочее', 'kind' => 'server_memory', 'threshold' => 5]);
        $this->server(['memory' => ['used' => 9, 'total' => 10]]);

        $real = $this->app->make(ConditionEvaluator::class);
        $this->mock(ConditionEvaluator::class, function ($mock) use ($broken, $real) {
            $mock->shouldReceive('observe')->andReturnUsing(fn (AlertRule $r) => $r->is($broken) ? throw new \RuntimeException('boom') : $real->observe($r));
        });
        $this->assertSame(1, $this->app->make(AlertEngine::class)->run()['fired']);
        $this->assertSame('Память занята на 90%', Alert::sole()->message);
    }

    public function test_telegram_and_email_channels_receive_firing_and_resolved(): void
    {
        Mail::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $telegram = AlertChannel::create(['name' => 'Чат', 'type' => 'telegram', 'config' => ['bot_token' => '1:A', 'chat_id' => '-5']]);
        $email = AlertChannel::create(['name' => 'Почта', 'type' => 'email', 'config' => ['recipients' => ['ops@example.com']]]);
        $this->rule(['severity' => 'critical'], [$telegram, $email]);
        $server = $this->server(['cpu' => 97.0], ['name' => 'web-1']);

        $this->run_();
        $this->metrics($server, ['cpu' => 10.0]);
        $this->run_();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/bot1:A/sendMessage') && $r['parse_mode'] === 'HTML' && str_starts_with($r['text'], '🔴 <b>Критично: Сервер web-1</b>') && str_contains($r['text'], 'CPU 97%') && str_contains($r['text'], 'Объект: Сервер «web-1»'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && str_starts_with($r['text'], '✅ <b>Решено: Сервер web-1</b>'));
        Mail::assertSent(AlertMail::class, 2);
        Mail::assertSent(AlertMail::class, fn ($m) => $m->hasTo('ops@example.com') && $m->envelope()->subject === '[КРИТИЧНО] Сервер web-1: CPU 97%');
        $this->assertSame(4, AlertNotification::where('ok', true)->count());
    }

    // --- закрытие без уведомления

    public function test_disabled_rule_closes_its_alerts_silently(): void
    {
        $rule = $this->rule([], [$this->channel()]);
        $this->server(['cpu' => 97.0]);
        $this->run_();

        $rule->update(['enabled' => false]);
        $this->run_();

        $this->assertSame(Alert::RESOLVED, Alert::sole()->status);
        $this->assertSame(1, AlertNotification::count());
    }

    public function test_disabled_rule_does_not_fire_and_drops_pending(): void
    {
        $rule = $this->rule(['duration' => 5]);
        $this->server(['cpu' => 97.0]);
        $this->run_();
        $this->assertSame(1, AlertPending::count());

        $rule->update(['enabled' => false]);
        $this->run_();

        $this->assertSame(0, AlertPending::count() + Alert::count());
    }

    public function test_object_leaving_scope_closes_alert_silently(): void
    {
        $a = $this->server(['cpu' => 97.0]);
        $b = $this->server(['cpu' => 97.0]);
        $rule = $this->rule(['targets' => [['type' => 'server', 'id' => $a->id], ['type' => 'server', 'id' => $b->id]]], [$this->channel()]);
        $this->run_();
        $this->assertSame(2, Alert::where('status', 'firing')->count());

        // убрали сервер из targets, второй удалили
        $rule->update(['targets' => [['type' => 'server', 'id' => $a->id]]]);
        $b->delete();
        $this->run_();

        $this->assertSame([$a->id], Alert::where('status', 'firing')->pluck('object_id')->all());
        $this->assertSame(2, Alert::count());
        $this->assertSame(2, AlertNotification::count());
    }

    // --- нет данных

    public function test_stale_or_missing_metrics_neither_fire_nor_resolve(): void
    {
        $this->rule();
        $server = $this->server(['cpu' => 97.0]);
        $silent = Server::factory()->create();
        $this->run_();
        $this->assertSame(1, Alert::count());

        // данные устарели: алерт остаётся, новые не создаются
        $this->travel(10)->minutes();
        $this->assertSame(['fired' => 0, 'repeated' => 0, 'resolved' => 0], $this->run_());
        $this->assertSame(Alert::FIRING, Alert::sole()->status);

        // сервер без метрик (сбор выключен) для CPU тоже «не знаем»
        $this->metrics($silent, [], ['enabled' => false]);
        $this->run_();
        $this->assertSame(1, Alert::count());
        $this->assertNotNull($server);
    }

    // --- область

    public function test_scope_by_project_and_targets(): void
    {
        $p1 = Project::factory()->create();
        $p2 = Project::factory()->create();
        $inP1 = $this->server(['cpu' => 97.0], ['project_id' => $p1->id]);
        $inP1b = $this->server(['cpu' => 97.0], ['project_id' => $p1->id]);
        $inP2 = $this->server(['cpu' => 97.0], ['project_id' => $p2->id]);
        $none = $this->server(['cpu' => 97.0]);

        $this->rule(['name' => 'проект', 'project_id' => $p1->id]);
        $this->run_();
        $this->assertEqualsCanonicalizing([$inP1->id, $inP1b->id], Alert::pluck('object_id')->all());
        $this->assertSame([$p1->id, $p1->id], Alert::pluck('project_id')->all());

        Alert::query()->delete();
        AlertRule::query()->delete();
        $this->rule(['name' => 'все']);
        $this->run_();
        $this->assertSame(4, Alert::count());

        Alert::query()->delete();
        AlertRule::query()->delete();
        $this->rule(['name' => 'выбранные', 'targets' => [['type' => 'server', 'id' => $inP2->id], ['type' => 'server', 'id' => $none->id], ['type' => 'monitor', 'id' => 999]]]);
        $this->run_();
        $this->assertEqualsCanonicalizing([$inP2->id, $none->id], Alert::pluck('object_id')->all());
    }

    public function test_targets_of_other_type_leave_kind_without_objects(): void
    {
        $server = $this->server(['cpu' => 97.0]);
        $db = DatabaseConnection::factory()->create();
        // backup_failed для сервера и базы: выбрана только база — сервер не проверяется
        $this->rule(['kind' => 'backup_failed', 'threshold' => null, 'targets' => [['type' => 'database', 'id' => $db->id]]]);
        $this->failedBackup($server);
        $this->failedBackup($db);

        $this->run_();

        $this->assertSame([['database', $db->id]], Alert::get()->map(fn ($a) => [$a->object_type, $a->object_id])->all());
    }

    // --- виды правил

    public function test_server_memory_disk_load_and_unreachable(): void
    {
        $this->server([
            'memory' => ['used' => 93, 'total' => 100],
            'disks' => [
                ['mount' => '/', 'device' => '/dev/sda1', 'used' => 50, 'total' => 100],
                ['mount' => '/data', 'device' => '/dev/sdb1', 'used' => 91, 'total' => 100],
            ],
            'cpu_cores' => 4, 'load' => [9.0, 12.0, 8.0],
        ]);
        $this->rule(['name' => 'mem', 'kind' => 'server_memory', 'threshold' => 90, 'duration' => 0]);
        $this->rule(['name' => 'disk', 'kind' => 'server_disk', 'threshold' => 85]);
        $this->rule(['name' => 'load', 'kind' => 'server_load', 'threshold' => 2]);
        $this->rule(['name' => 'ok-disk', 'kind' => 'server_disk', 'threshold' => 95]);

        $this->run_();

        $messages = Alert::with('rule')->get()->mapWithKeys(fn ($a) => [$a->rule->name => $a->message])->all();
        $this->assertSame([
            'mem' => 'Память занята на 93%',
            'disk' => 'Диск «/data» заполнен на 91%',
            'load' => 'Нагрузка 3 на ядро (load5 12, ядер: 4)',
        ], $messages);
    }

    public function test_server_unreachable(): void
    {
        $server = $this->server();
        $this->rule(['kind' => 'server_unreachable', 'threshold' => null, 'duration' => 3, 'severity' => 'critical'], [$this->channel()]);
        $this->metrics($server, [], ['error' => 'Connection timed out']);

        $this->run_();
        $this->assertSame(0, Alert::count());

        $this->travel(3)->minutes();
        $this->metrics($server, [], ['error' => 'Connection timed out', 'collected_at' => now()->subMinutes(3)]);
        $this->run_();
        $this->assertSame("Сервер {$server->name} не отвечает: Connection timed out", Alert::sole()->message);

        // сервер ответил — решено
        $this->metrics($server);
        $this->run_();
        $this->assertSame(Alert::RESOLVED, Alert::sole()->status);
    }

    public function test_monitor_down(): void
    {
        $down = Monitor::factory()->create(['url' => 'https://example.com/health', 'status' => Monitor::DOWN, 'last_error' => 'Таймаут 30 с']);
        $up = Monitor::factory()->create(['status' => Monitor::UP]);
        $pending = Monitor::factory()->create(['status' => Monitor::PENDING]);
        $paused = Monitor::factory()->create(['status' => Monitor::DOWN, 'enabled' => false]);
        $tcp = Monitor::factory()->tcp(5432)->create(['status' => Monitor::DOWN, 'hostname' => 'db.local', 'last_error' => 'Отказ']);
        $this->rule(['kind' => 'monitor_down', 'threshold' => null, 'severity' => 'critical'], [$this->channel()]);

        $this->run_();

        $this->assertEqualsCanonicalizing([$down->id, $tcp->id], Alert::pluck('object_id')->all());
        $this->assertSame('Сайт example.com недоступен: Таймаут 30 с', Alert::where('object_id', $down->id)->value('message'));
        $this->assertSame('Порт db.local:5432 недоступен: Отказ', Alert::where('object_id', $tcp->id)->value('message'));

        // монитор восстановился
        $down->forceFill(['status' => Monitor::UP])->save();
        $this->run_();
        $this->assertSame(Alert::RESOLVED, Alert::where('object_id', $down->id)->value('status'));
        $this->assertNotNull($up);
        $this->assertNotNull($pending);
        $this->assertNotNull($paused);
    }

    public function test_monitor_down_message_links_to_object_in_notification(): void
    {
        config(['app.url' => 'https://sk.example.org/']);
        $monitor = Monitor::factory()->create(['status' => Monitor::DOWN, 'last_error' => 'x']);
        $this->rule(['kind' => 'monitor_down', 'threshold' => null], [$this->channel()]);

        $this->run_();

        Http::assertSent(fn (Request $r) => $r['url'] === "https://sk.example.org/monitors/{$monitor->id}" && str_contains($r['text'], "https://sk.example.org/monitors/{$monitor->id}"));
    }

    public function test_monitor_ssl(): void
    {
        $soon = Monitor::factory()->create(['ssl_valid_to' => now()->addDays(5)->addHour(), 'url' => 'https://soon.example.com']);
        $expired = Monitor::factory()->create(['ssl_valid_to' => now()->subDays(2), 'url' => 'https://old.example.com']);
        Monitor::factory()->create(['ssl_valid_to' => now()->addDays(60)]);
        Monitor::factory()->create(['ssl_valid_to' => null]);
        $this->rule(['kind' => 'monitor_ssl', 'threshold' => 14]);

        $this->run_();

        $this->assertEqualsCanonicalizing([$soon->id, $expired->id], Alert::pluck('object_id')->all());
        $this->assertStringStartsWith('Сертификат soon.example.com истекает через 5 дн.', Alert::where('object_id', $soon->id)->value('message'));
        $this->assertStringStartsWith('Сертификат old.example.com истёк', Alert::where('object_id', $expired->id)->value('message'));

        // продлили сертификат
        $soon->forceFill(['ssl_valid_to' => now()->addDays(80)])->save();
        $this->run_();
        $this->assertSame(Alert::RESOLVED, Alert::where('object_id', $soon->id)->value('status'));
    }

    private function backup(Server|DatabaseConnection $source, string $status, ?string $error = null, $finishedAt = null): Backup
    {
        $backup = new Backup(['trigger' => 'schedule', 'status' => $status, 'error' => $error, 'finished_at' => $finishedAt ?? now()]);
        $backup->source()->associate($source)->save();

        return $backup;
    }

    private function failedBackup(Server|DatabaseConnection $source): Backup
    {
        return $this->backup($source, Backup::FAILED, 'Нет места');
    }

    public function test_backup_failed_follows_the_latest_finished_backup(): void
    {
        $server = Server::factory()->create(['name' => 'web-1']);
        $db = DatabaseConnection::factory()->create(['name' => 'shop']);
        $healthy = Server::factory()->create();
        $this->backup($server, Backup::SUCCESS);
        $this->failedBackup($server);
        $this->backup($server, Backup::RUNNING);
        $this->failedBackup($db);
        $this->failedBackup($healthy);
        $this->backup($healthy, Backup::SUCCESS);
        $this->rule(['kind' => 'backup_failed', 'threshold' => null, 'severity' => 'critical'], [$this->channel()]);

        $this->run_();

        $this->assertEqualsCanonicalizing([['server', $server->id], ['database', $db->id]], Alert::get()->map(fn ($a) => [$a->object_type, $a->object_id])->all());
        $this->assertSame('Бекап сервера web-1 не удался: Нет места', Alert::where('object_type', 'server')->value('message'));
        $this->assertSame('Бекап базы shop не удался: Нет места', Alert::where('object_type', 'database')->value('message'));

        // следующий бекап удался
        $this->travel(1)->minute();
        $this->backup($server, Backup::SUCCESS);
        $this->run_();
        $this->assertSame([Alert::FIRING, Alert::RESOLVED], [Alert::where('object_type', 'database')->value('status'), Alert::where('object_type', 'server')->value('status')]);
    }

    public function test_backup_missing_only_for_sources_with_backup_enabled(): void
    {
        $stale = Server::factory()->create(['name' => 'old']);
        $fresh = Server::factory()->create();
        $disabled = Server::factory()->create();
        $never = Server::factory()->create(['name' => 'never']);
        $db = DatabaseConnection::factory()->create(['name' => 'shop', 'backup_enabled' => true]);
        foreach ([$stale, $fresh, $never] as $server) {
            $server->backupSettings()->create(['enabled' => true, 'paths' => ['/etc'], 'excludes' => [], 'pre_command_timeout' => 300, 'schedule' => '0 3 * * *', 'keep_count' => 7]);
        }
        $disabled->backupSettings()->create(['enabled' => false, 'paths' => [], 'excludes' => [], 'pre_command_timeout' => 300, 'schedule' => '0 3 * * *', 'keep_count' => 7]);
        $this->backup($stale, Backup::SUCCESS, null, now()->subHours(30));
        $this->backup($stale, Backup::FAILED, 'x', now()->subHours(1));
        $this->backup($fresh, Backup::SUCCESS, null, now()->subHours(3));
        $this->backup($disabled, Backup::SUCCESS, null, now()->subDays(10));
        // «никогда не было» считаем от создания настроек бекапа
        ServerBackupSettings::where('server_id', $never->id)->update(['created_at' => now()->subHours(40)]);
        DatabaseConnection::where('id', $db->id)->update(['created_at' => now()->subHours(2)]);
        $this->rule(['kind' => 'backup_missing', 'threshold' => 26]);

        $this->run_();

        $this->assertEqualsCanonicalizing([$stale->id, $never->id], Alert::pluck('object_id')->all());
        $this->assertStringStartsWith('Нет успешного бекапа сервера old уже 30 ч', Alert::where('object_id', $stale->id)->value('message'));
        $this->assertSame('Нет ни одного успешного бекапа сервера never уже 40 ч', Alert::where('object_id', $never->id)->value('message'));
    }

    public function test_storage_full_by_yandex_disk_quota(): void
    {
        config(['yandex_disk.base_url' => 'https://cloud-api.test/v1/disk']);
        Http::fake(['cloud-api.test/*' => Http::response(['total_space' => 1000, 'used_space' => 910, 'user' => ['login' => 'x']])]);
        $yandex = BackupStorage::create(['name' => 'Мой Диск', 'type' => 'yandex_disk', 'settings' => ['token' => 't', 'folder' => 'disk:/SK']]);
        // у S3 размера нет — пропускается
        BackupStorage::create(['name' => 'Бакет', 'type' => 's3', 'settings' => ['bucket' => 'b', 'key' => 'k', 'secret' => 's', 'endpoint' => 'https://s3.test', 'region' => 'r']]);
        $this->rule(['kind' => 'storage_full', 'threshold' => 90]);

        $this->run_();

        $alert = Alert::sole();
        $this->assertSame(['storage', $yandex->id], [$alert->object_type, $alert->object_id]);
        $this->assertNull($alert->project_id);
        $this->assertSame('Хранилище Мой Диск заполнено на 91% (свободно 90 Б)', $alert->message);
    }

    public function test_storage_rule_ignores_project_filter(): void
    {
        config(['yandex_disk.base_url' => 'https://cloud-api.test/v1/disk']);
        Http::fake(['cloud-api.test/*' => Http::response(['total_space' => 1000, 'used_space' => 990])]);
        BackupStorage::create(['name' => 'Диск', 'type' => 'yandex_disk', 'settings' => ['token' => 't']]);
        $this->rule(['kind' => 'storage_full', 'threshold' => 90, 'project_id' => Project::factory()->create()->id]);

        $this->run_();

        $this->assertSame(1, Alert::count());
    }

    // --- команда планировщика

    public function test_command_runs_engine(): void
    {
        $this->rule();
        $this->server(['cpu' => 97.0]);

        $this->artisan('alerts:evaluate')->expectsOutput('Сработало: 1, напоминаний: 0, решено: 0')->assertSuccessful();

        $this->assertSame(1, Alert::count());
    }

    public function test_command_skips_during_update(): void
    {
        $this->rule();
        $this->server(['cpu' => 97.0]);
        $this->mock(Updater::class, fn ($m) => $m->shouldReceive('locked')->andReturn(true));

        $this->artisan('alerts:evaluate')->assertSuccessful();

        $this->assertSame(0, Alert::count());
    }

    public function test_prune_removes_old_resolved_alerts_only(): void
    {
        $rule = $this->rule();
        $make = fn (string $status, $resolvedAt) => Alert::create([
            'alert_rule_id' => $rule->id, 'severity' => 'warning', 'status' => $status, 'object_type' => 'server', 'object_id' => 1, 'object_name' => 's',
            'message' => 'x', 'started_at' => now()->subDays(200), 'resolved_at' => $resolvedAt,
        ]);
        $old = $make(Alert::RESOLVED, now()->subDays(91));
        $recent = $make(Alert::RESOLVED, now()->subDays(10));
        $firing = $make(Alert::FIRING, null);

        $this->artisan('alerts:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$recent->id, $firing->id], Alert::pluck('id')->all());
        $this->assertNotNull($old);
    }

    public function test_disabled_channel_gets_nothing_and_rule_without_channels_still_creates_alert(): void
    {
        $this->rule([], []);
        $this->server(['cpu' => 97.0]);

        $this->run_();

        $this->assertSame(1, Alert::count());
        $this->assertSame(0, AlertNotification::count());
        Http::assertNothingSent();
    }
}
