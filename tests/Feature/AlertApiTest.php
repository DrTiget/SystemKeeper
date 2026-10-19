<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertChannel;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertApiTest extends TestCase
{
    use RefreshDatabase;

    /** Поля Alert из frontend/src/api/alerts.ts */
    private const ALERT = [
        'id', 'rule' => ['id', 'name', 'kind'], 'severity', 'status', 'object' => ['type', 'id', 'name', 'project'], 'message',
        'started_at', 'resolved_at', 'acknowledged_at', 'acknowledged_by', 'notifications',
    ];

    private User $user;

    private AlertRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs($this->user = User::factory()->create(['name' => 'Иван']));
        $this->rule = AlertRule::create(['name' => 'Монитор недоступен', 'kind' => 'monitor_down', 'severity' => 'critical']);
    }

    private function alert(array $attrs = []): Alert
    {
        return Alert::create($attrs + [
            'alert_rule_id' => $this->rule->id, 'severity' => 'critical', 'status' => Alert::FIRING, 'object_type' => 'monitor', 'object_id' => 1,
            'object_name' => 'example.com', 'message' => 'Сайт example.com недоступен: timeout', 'started_at' => now()->subMinutes(10),
        ]);
    }

    public function test_requires_auth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/alerts')->assertUnauthorized();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/alerts/summary')->assertUnauthorized();
    }

    public function test_list_exactly_like_alerts_view(): void
    {
        $project = Project::factory()->create(['name' => 'Магазин']);
        $channel = AlertChannel::create(['name' => 'Чат', 'type' => 'telegram', 'config' => ['bot_token' => '1:A', 'chat_id' => '1']]);
        $alert = $this->alert(['project_id' => $project->id]);
        AlertNotification::create(['alert_id' => $alert->id, 'alert_channel_id' => $channel->id, 'event' => 'firing', 'ok' => false, 'error' => 'Telegram: chat not found', 'created_at' => now()]);

        // AlertsView.vue: status, per_page=30, page=1
        $this->getJson('/api/v1/alerts?status=firing&per_page=30&page=1')->assertOk()
            ->assertJsonStructure(['data' => ['*' => self::ALERT], 'meta' => ['current_page', 'last_page', 'total']])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.rule', ['id' => $this->rule->id, 'name' => 'Монитор недоступен', 'kind' => 'monitor_down'])
            ->assertJsonPath('data.0.object.project', ['id' => $project->id, 'name' => 'Магазин'])
            ->assertJsonPath('data.0.object.name', 'example.com')
            ->assertJsonPath('data.0.message', 'Сайт example.com недоступен: timeout')
            ->assertJsonPath('data.0.resolved_at', null)
            ->assertJsonPath('data.0.acknowledged_by', null)
            ->assertJsonPath('data.0.notifications.0.channel', ['id' => $channel->id, 'name' => 'Чат', 'type' => 'telegram'])
            ->assertJsonPath('data.0.notifications.0.ok', false)
            ->assertJsonPath('data.0.notifications.0.error', 'Telegram: chat not found')
            ->assertJsonStructure(['data' => ['*' => ['notifications' => ['*' => ['channel', 'at', 'ok', 'error']]]]]);
    }

    public function test_project_page_request_with_per_page_10(): void
    {
        $project = Project::factory()->create();
        $this->alert(['project_id' => $project->id]);
        $this->alert(['object_id' => 2]);

        // ProjectDetailView.vue
        $this->getJson("/api/v1/alerts?status=firing&project_id={$project->id}&per_page=10")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonStructure(['data' => ['*' => self::ALERT]]);
    }

    public function test_filters_and_order(): void
    {
        $warning = $this->alert(['severity' => 'warning', 'object_id' => 1, 'started_at' => now()->subMinutes(1)]);
        $oldCritical = $this->alert(['severity' => 'critical', 'object_id' => 2, 'started_at' => now()->subHours(3)]);
        $newCritical = $this->alert(['severity' => 'critical', 'object_id' => 3, 'started_at' => now()->subHour()]);
        $resolvedOld = $this->alert(['status' => Alert::RESOLVED, 'object_id' => 4, 'started_at' => now()->subDay(), 'resolved_at' => now()->subHours(20)]);
        $resolvedNew = $this->alert(['status' => Alert::RESOLVED, 'object_id' => 5, 'started_at' => now()->subDays(2), 'resolved_at' => now()->subHours(2)]);

        // активные: сначала критичные, внутри — новые сверху
        $this->assertSame([$newCritical->id, $oldCritical->id, $warning->id], collect($this->getJson('/api/v1/alerts?status=firing')->json('data'))->pluck('id')->all());
        $this->assertSame([$resolvedNew->id, $resolvedOld->id], collect($this->getJson('/api/v1/alerts?status=resolved')->json('data'))->pluck('id')->all());
        $this->getJson('/api/v1/alerts?status=firing&severity=warning')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $warning->id);
        $this->getJson('/api/v1/alerts')->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/alerts?per_page=2&page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 3);
    }

    public function test_list_validates_query(): void
    {
        $this->getJson('/api/v1/alerts?status=done')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/v1/alerts?severity=fatal')->assertUnprocessable()->assertJsonValidationErrors('severity');
        $this->getJson('/api/v1/alerts?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_summary(): void
    {
        $this->getJson('/api/v1/alerts/summary')->assertOk()->assertExactJson(['data' => ['firing' => 0, 'critical' => 0]]);

        $this->alert(['severity' => 'critical', 'object_id' => 1]);
        $this->alert(['severity' => 'warning', 'object_id' => 2]);
        $this->alert(['severity' => 'critical', 'object_id' => 3, 'status' => Alert::RESOLVED, 'resolved_at' => now()]);

        $this->getJson('/api/v1/alerts/summary')->assertOk()->assertExactJson(['data' => ['firing' => 2, 'critical' => 1]]);
    }

    public function test_ack_marks_alert_and_is_idempotent(): void
    {
        $alert = $this->alert();

        $this->postJson("/api/v1/alerts/{$alert->id}/ack")->assertOk()
            ->assertJsonStructure(['data' => self::ALERT])
            ->assertJsonPath('data.acknowledged_by', ['id' => $this->user->id, 'name' => 'Иван'])
            ->assertJsonPath('data.acknowledged_at', fn ($v) => $v !== null);

        // повторное нажатие другим пользователем ничего не меняет
        $first = $alert->refresh()->acknowledged_at;
        $this->travel(5)->minutes();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/alerts/{$alert->id}/ack")->assertOk()->assertJsonPath('data.acknowledged_by.id', $this->user->id);
        $this->assertEquals($first, $alert->refresh()->acknowledged_at);
    }

    public function test_ack_of_resolved_or_missing_alert(): void
    {
        $alert = $this->alert(['status' => Alert::RESOLVED, 'resolved_at' => now()]);

        $this->postJson("/api/v1/alerts/{$alert->id}/ack")->assertStatus(409);
        $this->postJson('/api/v1/alerts/999/ack')->assertNotFound();
        $this->assertNull($alert->refresh()->acknowledged_at);
    }
}
