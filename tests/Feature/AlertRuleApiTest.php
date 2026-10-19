<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertChannel;
use App\Models\AlertRule;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertRuleApiTest extends TestCase
{
    use RefreshDatabase;

    /** Поля AlertRule из frontend/src/api/alerts.ts */
    private const RULE = [
        'id', 'name', 'enabled', 'kind', 'severity', 'threshold', 'duration', 'project_id', 'targets', 'channel_ids', 'repeat_every',
        'notify_resolved', 'project', 'objects', 'channels', 'firing_count', 'last_fired_at', 'created_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    private function channel(string $name = 'Чат'): AlertChannel
    {
        return AlertChannel::create(['name' => $name, 'type' => 'telegram', 'config' => ['bot_token' => '1:A', 'chat_id' => '1']]);
    }

    /** Тело ровно как у AlertRuleFormView.vue: все поля AlertRuleInput */
    private function body(array $override = []): array
    {
        return $override + [
            'name' => 'CPU выше 90% дольше 5 мин', 'enabled' => true, 'kind' => 'server_cpu', 'severity' => 'warning',
            'threshold' => 90, 'duration' => 5, 'project_id' => null, 'targets' => [], 'channel_ids' => [],
            'repeat_every' => null, 'notify_resolved' => true,
        ];
    }

    public function test_requires_auth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/alert-rules')->assertUnauthorized();
    }

    public function test_create_returns_full_rule_with_names(): void
    {
        $project = Project::factory()->create(['name' => 'Магазин']);
        $server = Server::factory()->create(['name' => 'web-1', 'project_id' => $project->id]);
        $channel = $this->channel('Дежурный чат');

        $response = $this->postJson('/api/v1/alert-rules', $this->body([
            'project_id' => $project->id, 'targets' => [['type' => 'server', 'id' => $server->id]], 'channel_ids' => [$channel->id],
            'repeat_every' => 60, 'severity' => 'critical',
        ]));
        $response->assertCreated()
            ->assertJsonStructure(['data' => self::RULE])
            ->assertJsonPath('data.kind', 'server_cpu')
            ->assertJsonPath('data.severity', 'critical')
            ->assertJsonPath('data.threshold', 90)
            ->assertJsonPath('data.duration', 5)
            ->assertJsonPath('data.project', ['id' => $project->id, 'name' => 'Магазин'])
            ->assertJsonPath('data.objects', [['type' => 'server', 'id' => $server->id, 'name' => 'web-1']])
            ->assertJsonPath('data.channel_ids', [$channel->id])
            ->assertJsonPath('data.channels', [['id' => $channel->id, 'name' => 'Дежурный чат', 'type' => 'telegram']])
            ->assertJsonPath('data.repeat_every', 60)
            ->assertJsonPath('data.firing_count', 0)
            ->assertJsonPath('data.last_fired_at', null);
        $this->assertEquals([['type' => 'server', 'id' => $server->id]], $response->json('data.targets'));

        $this->assertSame(1, AlertRule::count());
    }

    public function test_every_kind_can_be_created_with_frontend_defaults(): void
    {
        // значения по умолчанию из ALERT_KINDS (frontend/src/api/alerts.ts)
        $kinds = [
            'server_cpu' => [90, 5, 'warning'], 'server_memory' => [90, 5, 'warning'], 'server_disk' => [85, 0, 'warning'], 'server_load' => [2, 10, 'warning'],
            'server_unreachable' => [null, 3, 'critical'], 'monitor_down' => [null, 0, 'critical'], 'monitor_ssl' => [14, 0, 'warning'],
            'backup_failed' => [null, 0, 'critical'], 'backup_missing' => [26, 0, 'warning'], 'storage_full' => [90, 0, 'warning'],
        ];
        foreach ($kinds as $kind => [$threshold, $duration, $severity]) {
            $this->postJson('/api/v1/alert-rules', $this->body(['name' => $kind, 'kind' => $kind, 'threshold' => $threshold, 'duration' => $duration, 'severity' => $severity]))
                ->assertCreated()->assertJsonPath('data.threshold', $threshold);
        }
        $this->assertSame(10, AlertRule::count());
    }

    public function test_threshold_is_dropped_for_kinds_without_it_and_required_for_others(): void
    {
        $this->postJson('/api/v1/alert-rules', $this->body(['kind' => 'monitor_down', 'threshold' => 50]))->assertCreated()->assertJsonPath('data.threshold', null);

        $this->postJson('/api/v1/alert-rules', $this->body(['threshold' => null]))->assertUnprocessable()->assertJsonValidationErrors('threshold');
        $this->postJson('/api/v1/alert-rules', $this->body(['threshold' => 101]))->assertUnprocessable()->assertJsonValidationErrors('threshold');
        $this->postJson('/api/v1/alert-rules', $this->body(['threshold' => 0]))->assertUnprocessable()->assertJsonValidationErrors('threshold');
        $this->postJson('/api/v1/alert-rules', $this->body(['kind' => 'server_load', 'threshold' => 0.5]))->assertCreated()->assertJsonPath('data.threshold', 0.5);
        $this->postJson('/api/v1/alert-rules', $this->body(['kind' => 'monitor_ssl', 'threshold' => 91]))->assertUnprocessable()->assertJsonValidationErrors('threshold');
        $this->postJson('/api/v1/alert-rules', $this->body(['kind' => 'backup_missing', 'threshold' => 721]))->assertUnprocessable()->assertJsonValidationErrors('threshold');
    }

    public function test_create_validates_fields(): void
    {
        $this->postJson('/api/v1/alert-rules', $this->body(['kind' => 'weather']))->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson('/api/v1/alert-rules', $this->body(['severity' => 'fatal']))->assertUnprocessable()->assertJsonValidationErrors('severity');
        $this->postJson('/api/v1/alert-rules', $this->body(['duration' => -1]))->assertUnprocessable()->assertJsonValidationErrors('duration');
        $this->postJson('/api/v1/alert-rules', $this->body(['duration' => 2000]))->assertUnprocessable()->assertJsonValidationErrors('duration');
        $this->postJson('/api/v1/alert-rules', $this->body(['project_id' => 999]))->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->postJson('/api/v1/alert-rules', $this->body(['channel_ids' => [999]]))->assertUnprocessable()->assertJsonValidationErrors('channel_ids.0');
        $this->postJson('/api/v1/alert-rules', $this->body(['repeat_every' => 1]))->assertUnprocessable()->assertJsonValidationErrors('repeat_every');
        $this->postJson('/api/v1/alert-rules', ['kind' => 'server_cpu', 'severity' => 'warning', 'threshold' => 90])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame(0, AlertRule::count());
    }

    public function test_targets_must_match_kind_and_exist(): void
    {
        $server = Server::factory()->create();
        $monitor = Monitor::factory()->create();

        // тип объекта не подходит к виду правила
        $this->postJson('/api/v1/alert-rules', $this->body(['targets' => [['type' => 'monitor', 'id' => $monitor->id]]]))->assertUnprocessable()->assertJsonValidationErrors('targets.0.type');
        // объекта нет — ошибка на `targets`, как показывает форма
        $this->postJson('/api/v1/alert-rules', $this->body(['targets' => [['type' => 'server', 'id' => $server->id], ['type' => 'server', 'id' => 999]]]))->assertUnprocessable()->assertJsonValidationErrors('targets');
        // бекапы — сервер и база
        $db = DatabaseConnection::factory()->create();
        $this->postJson('/api/v1/alert-rules', $this->body(['kind' => 'backup_failed', 'threshold' => null, 'targets' => [['type' => 'server', 'id' => $server->id], ['type' => 'database', 'id' => $db->id], ['type' => 'server', 'id' => $server->id]]]))
            ->assertCreated()->assertJsonCount(2, 'data.targets')->assertJsonCount(2, 'data.objects');
        $storage = BackupStorage::default();
        $this->postJson('/api/v1/alert-rules', $this->body(['kind' => 'storage_full', 'targets' => [['type' => 'storage', 'id' => $storage->id]]]))
            ->assertCreated()->assertJsonPath('data.objects.0.name', $storage->name);
    }

    public function test_index_show_and_project_filter(): void
    {
        $project = Project::factory()->create();
        $channel = $this->channel();
        $global = AlertRule::create(['name' => 'Б общее', 'kind' => 'monitor_down', 'severity' => 'critical']);
        $mine = AlertRule::create(['name' => 'А проектное', 'kind' => 'server_cpu', 'severity' => 'warning', 'threshold' => 90, 'project_id' => $project->id]);
        $mine->channels()->sync([$channel->id]);

        $this->getJson('/api/v1/alert-rules')->assertOk()
            ->assertJsonStructure(['data' => ['*' => self::RULE]])
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.1.id', $global->id);
        $this->getJson("/api/v1/alert-rules?project_id={$project->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson('/api/v1/alert-rules?project_id=')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/alert-rules/{$mine->id}")->assertOk()->assertJsonStructure(['data' => self::RULE])->assertJsonPath('data.channels.0.name', 'Чат');
        $this->getJson('/api/v1/alert-rules/999')->assertNotFound();
    }

    public function test_firing_count_and_last_fired_at(): void
    {
        $rule = AlertRule::create(['name' => 'r', 'kind' => 'monitor_down', 'severity' => 'critical', 'last_fired_at' => now()->subHour()]);
        foreach ([Alert::FIRING, Alert::FIRING, Alert::RESOLVED] as $i => $status) {
            Alert::create(['alert_rule_id' => $rule->id, 'severity' => 'critical', 'status' => $status, 'object_type' => 'monitor', 'object_id' => $i + 1, 'object_name' => 'm', 'message' => 'x', 'started_at' => now()]);
        }

        $this->getJson('/api/v1/alert-rules')->assertJsonPath('data.0.firing_count', 2)->assertJsonPath('data.0.last_fired_at', fn ($v) => $v !== null);
    }

    public function test_patch_enabled_only_like_frontend_toggle(): void
    {
        $rule = AlertRule::create(['name' => 'r', 'kind' => 'server_cpu', 'severity' => 'warning', 'threshold' => 90, 'duration' => 5]);
        $rule->channels()->sync([$this->channel()->id]);

        $this->patchJson("/api/v1/alert-rules/{$rule->id}", ['enabled' => false])->assertOk()
            ->assertJsonStructure(['data' => self::RULE])
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.threshold', 90)
            ->assertJsonCount(1, 'data.channel_ids');
    }

    public function test_patch_full_body_replaces_fields_and_channels(): void
    {
        $a = $this->channel('A');
        $b = $this->channel('B');
        $server = Server::factory()->create();
        $rule = AlertRule::create(['name' => 'r', 'kind' => 'server_cpu', 'severity' => 'warning', 'threshold' => 90, 'duration' => 5, 'targets' => [['type' => 'server', 'id' => $server->id]]]);
        $rule->channels()->sync([$a->id]);

        $this->patchJson("/api/v1/alert-rules/{$rule->id}", $this->body(['name' => 'Новое', 'kind' => 'server_memory', 'threshold' => 80, 'duration' => 0, 'targets' => [], 'channel_ids' => [$b->id], 'repeat_every' => 30, 'notify_resolved' => false]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Новое')
            ->assertJsonPath('data.kind', 'server_memory')
            ->assertJsonPath('data.threshold', 80)
            ->assertJsonPath('data.targets', [])
            ->assertJsonPath('data.channel_ids', [$b->id])
            ->assertJsonPath('data.repeat_every', 30)
            ->assertJsonPath('data.notify_resolved', false);

        // смена вида на тот, где нужен порог, без порога — ошибка
        $this->patchJson("/api/v1/alert-rules/{$rule->id}", ['kind' => 'server_disk'])->assertUnprocessable()->assertJsonValidationErrors('threshold');
        // пустой список каналов отвязывает все
        $this->patchJson("/api/v1/alert-rules/{$rule->id}", ['channel_ids' => []])->assertOk()->assertJsonPath('data.channels', []);
    }

    public function test_destroy_removes_rule_with_alerts_and_survives_missing_objects(): void
    {
        $server = Server::factory()->create();
        $rule = AlertRule::create(['name' => 'r', 'kind' => 'server_cpu', 'severity' => 'warning', 'threshold' => 90, 'targets' => [['type' => 'server', 'id' => $server->id]]]);
        Alert::create(['alert_rule_id' => $rule->id, 'severity' => 'warning', 'status' => 'firing', 'object_type' => 'server', 'object_id' => $server->id, 'object_name' => 'x', 'message' => 'x', 'started_at' => now()]);

        // сервер удалили — правило показывается, объект помечен удалённым
        $server->delete();
        $this->getJson("/api/v1/alert-rules/{$rule->id}")->assertOk()->assertJsonPath('data.objects.0.name', "Удалён (#{$server->id})");

        $this->deleteJson("/api/v1/alert-rules/{$rule->id}")->assertNoContent();
        $this->assertSame(0, AlertRule::count() + Alert::count());
    }

    public function test_deleting_project_deletes_its_rules_instead_of_widening_them(): void
    {
        $project = Project::factory()->create();
        AlertRule::create(['name' => 'проектное', 'kind' => 'monitor_down', 'severity' => 'critical', 'project_id' => $project->id]);
        $global = AlertRule::create(['name' => 'общее', 'kind' => 'monitor_down', 'severity' => 'critical']);

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        $this->assertSame([$global->id], AlertRule::pluck('id')->all());
    }
}
