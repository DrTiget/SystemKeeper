<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use App\Models\MonitorIncident;
use App\Models\Project;
use App\Models\User;
use App\Services\Monitoring\CheckResult;
use App\Services\Monitoring\MonitorChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeMonitorChecker;
use Tests\TestCase;

class MonitorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    private function fake(CheckResult ...$results): FakeMonitorChecker
    {
        $fake = new FakeMonitorChecker($results);
        $this->app->instance(MonitorChecker::class, $fake);

        return $fake;
    }

    private function beat(Monitor $m, string $status, string $at, ?int $ms = 100): void
    {
        MonitorHeartbeat::create(['monitor_id' => $m->id, 'status' => $status, 'response_ms' => $ms, 'created_at' => $at]);
    }

    public function test_requires_auth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/monitors')->assertUnauthorized();
    }

    public function test_create_http_monitor_with_defaults(): void
    {
        $project = Project::factory()->create();

        $this->postJson('/api/v1/monitors', [
            'name' => 'Сайт', 'type' => 'http', 'url' => 'https://example.com', 'project_id' => $project->id,
            'headers' => [['name' => 'Authorization', 'value' => 'Bearer secret']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.project.name', $project->name)
            ->assertJsonPath('data.method', 'GET')
            ->assertJsonPath('data.accepted_statuses', ['200-299'])
            ->assertJsonPath('data.interval', 60)
            ->assertJsonPath('data.headers.0.name', 'Authorization')
            ->assertJsonPath('data.uptime.h24', null)
            ->assertJsonPath('data.recent', []);

        // заголовки в БД зашифрованы
        $this->assertStringNotContainsString('secret', (string) \DB::table('monitors')->value('headers'));
    }

    public function test_validation_depends_on_type(): void
    {
        $this->postJson('/api/v1/monitors', ['name' => 'x', 'type' => 'http'])->assertJsonValidationErrors('url');
        $this->postJson('/api/v1/monitors', ['name' => 'x', 'type' => 'tcp', 'hostname' => 'db.local'])->assertJsonValidationErrors('port');
        $this->postJson('/api/v1/monitors', ['name' => 'x', 'type' => 'ping'])->assertJsonValidationErrors('hostname');
        $this->postJson('/api/v1/monitors', ['name' => 'x', 'type' => 'dns', 'hostname' => '-oX'])->assertJsonValidationErrors('hostname');
        $this->postJson('/api/v1/monitors', ['name' => 'x', 'type' => 'http', 'url' => 'ftp://x.io'])->assertJsonValidationErrors('url');
        $this->postJson('/api/v1/monitors', ['name' => 'x', 'type' => 'http', 'url' => 'https://x.io', 'interval' => 5])->assertJsonValidationErrors('interval');
        $this->postJson('/api/v1/monitors', ['name' => 'x', 'type' => 'http', 'url' => 'https://x.io', 'accepted_statuses' => ['abc']])->assertJsonValidationErrors('accepted_statuses.0');
        $this->postJson('/api/v1/monitors', ['name' => 'x', 'type' => 'http', 'url' => 'https://x.io', 'headers' => [['name' => "X\nY", 'value' => 'v']]])->assertJsonValidationErrors('headers.0.name');
        $this->postJson('/api/v1/monitors', ['name' => 'x', 'type' => 'dns', 'hostname' => 'a.io', 'dns_resolver' => 'not-ip'])->assertJsonValidationErrors('dns_resolver');
        $this->postJson('/api/v1/monitors', ['name' => 'x', 'type' => 'tcp', 'hostname' => 'a.io', 'port' => 22])->assertCreated();
    }

    public function test_patch_pause_and_partial(): void
    {
        $m = Monitor::factory()->create();

        $this->patchJson("/api/v1/monitors/{$m->id}", ['enabled' => false])->assertOk()->assertJsonPath('data.status', 'paused');
        $this->patchJson("/api/v1/monitors/{$m->id}", ['name' => 'Новое'])->assertOk()->assertJsonPath('data.name', 'Новое')->assertJsonPath('data.url', 'https://example.com');
        $this->patchJson("/api/v1/monitors/{$m->id}", ['url' => null])->assertJsonValidationErrors('url');
    }

    public function test_list_filters_and_stats(): void
    {
        $a = Monitor::factory()->create(['name' => 'A', 'status' => 'up']);
        $b = Monitor::factory()->create(['name' => 'B', 'status' => 'down']);
        Monitor::factory()->create(['name' => 'C', 'enabled' => false, 'status' => 'up']);

        $this->beat($a, 'up', now()->subHours(2), 100);
        $this->beat($a, 'up', now()->subHour(), 300);
        $this->beat($a, 'down', now()->subDays(3), null);
        $this->beat($a, 'up', now()->subDays(20), 50);
        $this->beat($a, 'pending', now()->subMinutes(5), null);

        $res = $this->getJson('/api/v1/monitors')->assertOk()->assertJsonCount(3, 'data');
        $res->assertJsonPath('data.0.uptime.h24', 100)
            ->assertJsonPath('data.0.uptime.d7', 66.67)
            ->assertJsonPath('data.0.uptime.d30', 75)
            ->assertJsonPath('data.0.avg_response_ms', 200)
            ->assertJsonCount(5, 'data.0.recent')
            ->assertJsonPath('data.0.recent.0.status', 'up')
            ->assertJsonPath('data.0.recent.4.status', 'pending')
            ->assertJsonPath('data.1.uptime.h24', null)
            ->assertJsonPath('data.2.status', 'paused');

        $this->getJson('/api/v1/monitors?status=down')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
        $this->getJson('/api/v1/monitors?status=paused')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'C');
        $this->getJson('/api/v1/monitors?status=up')->assertJsonCount(1, 'data');
    }

    public function test_recent_is_limited_to_last_50_oldest_first(): void
    {
        $m = Monitor::factory()->create();
        foreach (range(1, 60) as $i) {
            $this->beat($m, 'up', now()->subMinutes(61 - $i), $i);
        }

        $r = $this->getJson("/api/v1/monitors/{$m->id}")->assertOk()->assertJsonCount(50, 'data.recent');
        $this->assertSame(11, $r->json('data.recent.0.response_ms'));
        $this->assertSame(60, $r->json('data.recent.49.response_ms'));
    }

    public function test_project_filter_and_delete_cascades(): void
    {
        $p = Project::factory()->create();
        $in = Monitor::factory()->create(['project_id' => $p->id]);
        Monitor::factory()->create();
        $this->beat($in, 'up', now());
        MonitorIncident::create(['monitor_id' => $in->id, 'started_at' => now()]);

        $this->getJson("/api/v1/monitors?project_id={$p->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/monitors?project_id=none')->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/monitors/{$in->id}")->assertNoContent();
        $this->assertDatabaseCount('monitor_heartbeats', 0);
        $this->assertDatabaseCount('monitor_incidents', 0);
        $p->delete();
    }

    public function test_test_endpoint_does_not_save(): void
    {
        $fake = $this->fake(CheckResult::down('connection refused', 12));

        $this->postJson('/api/v1/monitors/test', ['name' => 'x', 'type' => 'http', 'url' => 'https://example.com'])
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'down', 'response_ms' => 12, 'status_code' => null, 'message' => 'connection refused']]);

        $this->assertDatabaseCount('monitors', 0);
        $this->assertSame('GET', $fake->checked[0]->method);
        $this->postJson('/api/v1/monitors/test', ['type' => 'tcp'])->assertUnprocessable();
    }

    public function test_check_now_records_heartbeat(): void
    {
        $this->fake(CheckResult::up(80, '200 OK', 200));
        $m = Monitor::factory()->create();

        $this->postJson("/api/v1/monitors/{$m->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'up')
            ->assertJsonPath('data.response_ms', 80)
            ->assertJsonPath('data.monitor.status', 'up')
            ->assertJsonPath('data.monitor.recent.0.message', '200 OK')
            ->assertJsonPath('data.monitor.uptime.h24', 100);
        $this->assertDatabaseCount('monitor_heartbeats', 1);
    }

    public function test_heartbeats_periods(): void
    {
        // Середина часа и 3-часового интервала, чтобы точки не делились границей усреднения
        $this->travelTo(Carbon::parse('2026-10-10 14:30:00', 'UTC'));
        $m = Monitor::factory()->create();
        $this->beat($m, 'up', now()->subHours(3), 100);
        $this->beat($m, 'down', now()->subHours(2), null);
        $this->beat($m, 'up', now()->subDays(3), 200);
        $this->beat($m, 'up', now()->subDays(3)->addMinute(), 400);
        $this->beat($m, 'up', now()->subDays(10), 50);
        $this->beat($m, 'up', now()->subDays(40), 50);

        $this->getJson("/api/v1/monitors/{$m->id}/heartbeats")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.status', 'down');
        $d7 = $this->getJson("/api/v1/monitors/{$m->id}/heartbeats?period=7d")->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(300, $d7->json('data.0.response_ms'));
        $this->getJson("/api/v1/monitors/{$m->id}/heartbeats?period=30d")->assertJsonCount(4, 'data');
        $this->getJson("/api/v1/monitors/{$m->id}/heartbeats?period=1y")->assertUnprocessable();
    }

    public function test_incidents_newest_first(): void
    {
        $m = Monitor::factory()->create();
        MonitorIncident::create(['monitor_id' => $m->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subHours(23), 'error' => 'старый']);
        MonitorIncident::create(['monitor_id' => $m->id, 'started_at' => now()->subHour(), 'error' => 'timeout']);

        $this->getJson("/api/v1/monitors/{$m->id}/incidents?per_page=10")
            ->assertOk()
            ->assertJsonPath('data.0.error', 'timeout')
            ->assertJsonPath('data.0.ended_at', null)
            ->assertJsonPath('data.1.error', 'старый');
    }

    /** Запросы ровно как во фронте (frontend/src/views/Monitor*.vue, ProjectDetailView.vue). */
    public function test_frontend_requests_are_accepted(): void
    {
        $project = Project::factory()->create();
        $this->fake(CheckResult::up(120, '200 OK', 200));

        // defaultMonitorInput() из frontend/src/api/monitors.ts
        $input = [
            'name' => 'Сайт', 'project_id' => $project->id, 'type' => 'http', 'enabled' => true,
            'url' => 'https://example.com', 'hostname' => null, 'port' => null,
            'method' => 'GET', 'accepted_statuses' => ['200-299'], 'keyword' => null, 'keyword_invert' => false,
            'headers' => [], 'body' => null, 'follow_redirects' => true, 'ignore_tls' => false, 'ssl_expiry_days' => 14,
            'dns_record_type' => 'A', 'dns_resolver' => null, 'dns_expected' => null,
            'interval' => 60, 'timeout' => 30, 'retries' => 1, 'retry_interval' => 60,
        ];

        $this->postJson('/api/v1/monitors/test', $input)->assertOk()->assertJsonPath('data.status', 'up');
        $id = $this->postJson('/api/v1/monitors', $input)->assertCreated()->json('data.id');

        $keys = ['id', 'project', 'status', 'status_since', 'last_check_at', 'last_response_ms', 'last_error',
            'uptime' => ['h24', 'd7', 'd30'], 'avg_response_ms', 'ssl', 'recent', 'created_at', ...array_keys($input)];
        $this->getJson('/api/v1/monitors?per_page=500')->assertOk()->assertJsonStructure(['data' => [$keys]]);
        $this->getJson("/api/v1/monitors?project_id={$project->id}&per_page=100")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/monitors/{$id}")->assertOk()->assertJsonStructure(['data' => $keys]);
        $this->postJson("/api/v1/monitors/{$id}/check")->assertOk()->assertJsonStructure(['data' => ['status', 'response_ms', 'status_code', 'message', 'monitor']]);
        foreach (['24h', '7d', '30d'] as $period) {
            $this->getJson("/api/v1/monitors/{$id}/heartbeats?period={$period}")->assertOk();
        }
        $this->getJson("/api/v1/monitors/{$id}/incidents?per_page=10")->assertOk();
        $this->patchJson("/api/v1/monitors/{$id}", ['enabled' => false])->assertOk()->assertJsonPath('data.status', 'paused');
        $this->patchJson("/api/v1/monitors/{$id}", $input)->assertOk();
        $this->deleteJson("/api/v1/monitors/{$id}")->assertSuccessful();
    }

    /** Разовая проверка настоящим чекером: заголовки и коды ответа доходят до запроса как массивы. */
    public function test_test_endpoint_with_network_checker(): void
    {
        Http::fake(['example.com/*' => Http::response('ok', 200)]);

        $this->postJson('/api/v1/monitors/test', [
            'name' => 'x', 'type' => 'http', 'url' => 'http://example.com/', 'method' => 'GET',
            'accepted_statuses' => ['200-299'], 'headers' => [['name' => 'X-Token', 'value' => 'abc']],
        ])->assertOk()->assertJsonPath('data.status', 'up')->assertJsonPath('data.status_code', 200);

        Http::assertSent(fn ($r) => $r->hasHeader('X-Token', 'abc'));

        $this->postJson('/api/v1/monitors/test', ['name' => 'x', 'type' => 'http', 'url' => 'http://example.com/'])
            ->assertOk()->assertJsonPath('data.status', 'up');
    }
}
