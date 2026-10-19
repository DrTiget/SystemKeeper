<?php

namespace Tests\Feature;

use App\Models\AlertChannel;
use App\Models\AlertRule;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerMetrics;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertRuleSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    /** Поля AlertSuggestion из frontend/src/api/alerts.ts */
    private const SUGGESTION = [
        'key', 'name', 'kind', 'severity', 'threshold', 'duration', 'project_id', 'targets', 'repeat_every', 'notify_resolved', 'reason', 'objects_count',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        // локальное хранилище по умолчанию создаёт миграция — в тестах хранилища заводятся явно
        BackupStorage::query()->delete();
    }

    private function channel(string $name = 'Чат'): AlertChannel
    {
        return AlertChannel::create(['name' => $name, 'type' => 'telegram', 'config' => ['bot_token' => '1:A', 'chat_id' => '1']]);
    }

    private function backupSettings(Server $server, bool $enabled = true): void
    {
        $server->backupSettings()->create(['enabled' => $enabled, 'paths' => ['/etc'], 'excludes' => [], 'pre_command_timeout' => 300, 'schedule' => '0 3 * * *', 'keep_count' => 7]);
    }

    /** @return array<string, array<string, mixed>> предложения по ключам */
    private function suggestions(): array
    {
        return collect($this->getJson('/api/v1/alert-rules/suggestions')->assertOk()->json('data'))->keyBy('key')->all();
    }

    public function test_requires_auth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/alert-rules/suggestions')->assertUnauthorized();
        $this->postJson('/api/v1/alert-rules/suggestions/apply', [])->assertUnauthorized();
    }

    public function test_no_suggestions_without_objects(): void
    {
        $this->getJson('/api/v1/alert-rules/suggestions')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_full_set_when_all_objects_exist(): void
    {
        $withBackup = Server::factory()->create();
        $this->backupSettings($withBackup);
        Server::factory()->create();
        DatabaseConnection::factory()->create(['backup_enabled' => true]);
        Monitor::factory()->create(['url' => 'https://a.test']);
        Monitor::factory()->tcp()->create();
        BackupStorage::create(['name' => 'Диск', 'type' => 'local', 'settings' => []]);

        $response = $this->getJson('/api/v1/alert-rules/suggestions')->assertOk();
        $response->assertJsonStructure(['data' => ['*' => self::SUGGESTION]]);
        $list = $this->suggestions();

        $this->assertSame([
            'server_unreachable', 'server_disk_warning', 'server_disk_critical', 'server_memory', 'server_cpu', 'server_load',
            'monitor_down', 'monitor_ssl', 'backup_failed', 'backup_missing', 'storage_full',
        ], array_keys($list));

        // значения совпадают с ALERT_KINDS.defaults из фронта (+ добавочный диск 95%)
        $expected = [
            'server_unreachable' => ['server_unreachable', 'critical', null, 3],
            'server_disk_warning' => ['server_disk', 'warning', 85, 0],
            'server_disk_critical' => ['server_disk', 'critical', 95, 0],
            'server_memory' => ['server_memory', 'warning', 90, 5],
            'server_cpu' => ['server_cpu', 'warning', 90, 5],
            'server_load' => ['server_load', 'warning', 2, 10],
            'monitor_down' => ['monitor_down', 'critical', null, 0],
            'monitor_ssl' => ['monitor_ssl', 'warning', 14, 0],
            'backup_failed' => ['backup_failed', 'critical', null, 0],
            'backup_missing' => ['backup_missing', 'warning', 26, 0],
            'storage_full' => ['storage_full', 'warning', 90, 0],
        ];
        foreach ($expected as $key => [$kind, $severity, $threshold, $duration]) {
            $s = $list[$key];
            $this->assertSame($kind, $s['kind'], $key);
            $this->assertSame($severity, $s['severity'], $key);
            $this->assertEquals($threshold, $s['threshold'], $key);
            $this->assertSame($duration, $s['duration'], $key);
            $this->assertNull($s['project_id']);
            $this->assertSame([], $s['targets']);
            $this->assertTrue($s['notify_resolved']);
            $this->assertSame($severity === 'critical' ? 60 : null, $s['repeat_every'], $key);
        }

        $this->assertSame(2, $list['server_cpu']['objects_count']);
        $this->assertSame('2 сервера с метриками', $list['server_cpu']['reason']);
        $this->assertSame(2, $list['monitor_down']['objects_count']);
        $this->assertSame('2 монитора', $list['monitor_down']['reason']);
        $this->assertSame(1, $list['monitor_ssl']['objects_count']);
        $this->assertSame('1 HTTPS-монитор', $list['monitor_ssl']['reason']);
        $this->assertSame(2, $list['backup_failed']['objects_count']);
        $this->assertSame('1 сервер и 1 база с бекапом по расписанию', $list['backup_failed']['reason']);
        $this->assertSame('1 хранилище с известным размером', $list['storage_full']['reason']);
    }

    public function test_each_group_needs_its_own_objects(): void
    {
        // только монитор по TCP: нет ни серверов, ни HTTPS, ни бекапов, ни хранилищ
        Monitor::factory()->tcp()->create();
        $this->assertSame(['monitor_down'], array_keys($this->suggestions()));

        // сервер без бекапа и с выключенным сбором метрик — тоже ничего серверного
        $server = Server::factory()->create();
        ServerMetrics::create(['server_id' => $server->id, 'enabled' => false]);
        $this->backupSettings($server, false);
        DatabaseConnection::factory()->create(['backup_enabled' => false]);
        BackupStorage::create(['name' => 'Бакет', 'type' => 's3', 'settings' => ['bucket' => 'b', 'key' => 'k', 'secret' => 's', 'endpoint' => 'https://s3.test', 'region' => 'r']]);
        Monitor::factory()->create(['url' => 'http://plain.test', 'enabled' => false]);
        $this->assertSame(['monitor_down'], array_keys($this->suggestions()));

        // сбор включён — появляются серверные правила
        ServerMetrics::where('server_id', $server->id)->update(['enabled' => true]);
        $this->assertSame(1, $this->suggestions()['server_cpu']['objects_count']);
    }

    public function test_only_backup_sources_give_backup_suggestions(): void
    {
        DatabaseConnection::factory()->count(3)->create(['backup_enabled' => true]);

        $list = $this->suggestions();
        $this->assertSame(['backup_failed', 'backup_missing'], array_keys($list));
        $this->assertSame('3 базы с бекапом по расписанию', $list['backup_failed']['reason']);
    }

    public function test_already_covered_rules_are_excluded(): void
    {
        Server::factory()->create();
        Monitor::factory()->create();
        $project = Project::factory()->create();

        // покрывают: включённое правило того же вида на все проекты без targets
        AlertRule::create(['name' => 'a', 'kind' => 'monitor_down', 'severity' => 'warning']);
        AlertRule::create(['name' => 'b', 'kind' => 'server_disk', 'severity' => 'warning', 'threshold' => 70]);
        // не покрывают: выключено / в проекте / на выбранные объекты / другая важность
        AlertRule::create(['name' => 'c', 'kind' => 'server_cpu', 'severity' => 'warning', 'threshold' => 80, 'enabled' => false]);
        AlertRule::create(['name' => 'd', 'kind' => 'server_memory', 'severity' => 'warning', 'threshold' => 80, 'project_id' => $project->id]);
        AlertRule::create(['name' => 'e', 'kind' => 'server_load', 'severity' => 'warning', 'threshold' => 3, 'targets' => [['type' => 'server', 'id' => 1]]]);
        AlertRule::create(['name' => 'f', 'kind' => 'monitor_ssl', 'severity' => 'critical', 'threshold' => 7]);

        $keys = array_keys($this->suggestions());
        $this->assertNotContains('monitor_down', $keys);
        $this->assertNotContains('server_disk_warning', $keys);
        $this->assertContains('server_disk_critical', $keys);
        foreach (['server_cpu', 'server_memory', 'server_load', 'monitor_ssl', 'server_unreachable'] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    public function test_apply_creates_rules_with_channels_and_they_disappear_from_suggestions(): void
    {
        Server::factory()->create();
        Monitor::factory()->create();
        $a = $this->channel('Чат');
        $b = $this->channel('Дежурный');

        $response = $this->postJson('/api/v1/alert-rules/suggestions/apply', [
            'keys' => ['server_unreachable', 'server_disk_warning', 'monitor_down'], 'channel_ids' => [$a->id, $b->id],
        ]);
        $response->assertCreated()->assertJsonCount(3, 'data');
        $rules = collect($response->json('data'))->keyBy('name');

        $unreachable = $rules['Сервер не отвечает'];
        $this->assertSame('server_unreachable', $unreachable['kind']);
        $this->assertSame('critical', $unreachable['severity']);
        $this->assertNull($unreachable['threshold']);
        $this->assertSame(3, $unreachable['duration']);
        $this->assertSame(60, $unreachable['repeat_every']);
        $this->assertTrue($unreachable['notify_resolved']);
        $this->assertTrue($unreachable['enabled']);
        $this->assertNull($unreachable['project_id']);
        $this->assertSame([], $unreachable['targets']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $unreachable['channel_ids']);
        $this->assertCount(2, $unreachable['channels']);

        $disk = $rules['Диск заполнен больше 85%'];
        $this->assertEquals(85, $disk['threshold']);
        $this->assertNull($disk['repeat_every']);

        $this->assertSame(3, AlertRule::count());
        $this->assertSame(2, AlertRule::where('kind', 'server_unreachable')->first()->channels()->count());

        $keys = array_keys($this->suggestions());
        foreach (['server_unreachable', 'server_disk_warning', 'monitor_down'] as $key) {
            $this->assertNotContains($key, $keys);
        }
        $this->assertContains('server_disk_critical', $keys);

        // повторное применение тех же ключей — 422, дублей нет
        $this->postJson('/api/v1/alert-rules/suggestions/apply', ['keys' => ['monitor_down'], 'channel_ids' => [$a->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('keys');
        $this->assertSame(3, AlertRule::count());
    }

    public function test_apply_everything_from_suggestions_as_the_frontend_does(): void
    {
        $server = Server::factory()->create();
        $this->backupSettings($server);
        DatabaseConnection::factory()->create(['backup_enabled' => true]);
        Monitor::factory()->create();
        BackupStorage::create(['name' => 'Диск', 'type' => 'local', 'settings' => []]);
        $channel = $this->channel();

        $keys = array_keys($this->suggestions());
        $this->postJson('/api/v1/alert-rules/suggestions/apply', ['keys' => $keys, 'channel_ids' => [$channel->id]])
            ->assertCreated()->assertJsonCount(count($keys), 'data');

        $this->assertSame(count($keys), AlertRule::count());
        $this->getJson('/api/v1/alert-rules/suggestions')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/alert-rules')->assertOk()->assertJsonCount(count($keys), 'data');
    }

    public function test_apply_rejects_unknown_key_and_creates_nothing(): void
    {
        Server::factory()->create();
        $channel = $this->channel();

        $this->postJson('/api/v1/alert-rules/suggestions/apply', ['keys' => ['server_cpu', 'nope'], 'channel_ids' => [$channel->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('keys');
        // ключ из набора, но объектов под него нет
        $this->postJson('/api/v1/alert-rules/suggestions/apply', ['keys' => ['monitor_down'], 'channel_ids' => [$channel->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('keys');
        $this->assertSame(0, AlertRule::count());
    }

    public function test_apply_requires_channels(): void
    {
        Server::factory()->create();
        $channel = $this->channel();

        $this->postJson('/api/v1/alert-rules/suggestions/apply', ['keys' => ['server_cpu'], 'channel_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('channel_ids');
        $this->postJson('/api/v1/alert-rules/suggestions/apply', ['keys' => ['server_cpu']])
            ->assertUnprocessable()->assertJsonValidationErrors('channel_ids');
        $this->postJson('/api/v1/alert-rules/suggestions/apply', ['keys' => ['server_cpu'], 'channel_ids' => [$channel->id + 100]])
            ->assertUnprocessable()->assertJsonValidationErrors('channel_ids.0');
        $this->postJson('/api/v1/alert-rules/suggestions/apply', ['keys' => [], 'channel_ids' => [$channel->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('keys');
        $this->assertSame(0, AlertRule::count());
    }

    public function test_apply_without_any_channel_explains_what_to_do(): void
    {
        Server::factory()->create();

        $errors = $this->postJson('/api/v1/alert-rules/suggestions/apply', ['keys' => ['server_cpu'], 'channel_ids' => [1]])
            ->assertUnprocessable()->assertJsonValidationErrors('channel_ids.0')->json('errors');
        $this->assertContains('Сначала создайте канал уведомлений: правилам некуда слать сообщения.', $errors['channel_ids']);
        $this->assertSame(0, AlertRule::count());
    }

    public function test_suggestions_route_is_not_swallowed_by_rule_show(): void
    {
        $this->getJson('/api/v1/alert-rules/suggestions')->assertOk()->assertJsonStructure(['data']);
    }
}
