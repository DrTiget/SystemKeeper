<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonitorApiTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_requires_authentication(): void
    {
        $monitor = Monitor::factory()->create();

        $this->getJson('/api/v1/monitors')->assertUnauthorized();
        $this->postJson('/api/v1/monitors', [])->assertUnauthorized();
        $this->getJson("/api/v1/monitors/{$monitor->id}")->assertUnauthorized();
        $this->deleteJson("/api/v1/monitors/{$monitor->id}")->assertUnauthorized();
        $this->postJson("/api/v1/monitors/{$monitor->id}/pause")->assertUnauthorized();
        $this->getJson("/api/v1/monitors/{$monitor->id}/checks")->assertUnauthorized();
        $this->getJson("/api/v1/monitors/{$monitor->id}/uptime")->assertUnauthorized();
        $this->getJson('/api/v1/incidents')->assertUnauthorized();
    }

    public function test_creates_http_monitor_with_defaults(): void
    {
        $this->login();

        $this->postJson('/api/v1/monitors', ['name' => 'Сайт', 'type' => 'http', 'target' => 'https://example.com'])
            ->assertCreated()
            ->assertJsonPath('data.interval_seconds', 60)
            ->assertJsonPath('data.failure_threshold', 3)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.heartbeat_token', null)
            ->assertJsonPath('data.status', null);
    }

    public function test_heartbeat_monitor_gets_token_and_url(): void
    {
        $this->login();

        $response = $this->postJson('/api/v1/monitors', ['name' => 'Cron', 'type' => 'heartbeat', 'interval_seconds' => 300])
            ->assertCreated();

        $token = $response->json('data.heartbeat_token');
        $this->assertSame(40, strlen($token));
        $this->assertStringEndsWith("/api/v1/heartbeat/{$token}", $response->json('data.heartbeat_url'));
    }

    public function test_validates_target_by_type(): void
    {
        $this->login();

        $this->postJson('/api/v1/monitors', ['name' => 'a', 'type' => 'http', 'target' => 'not a url'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->postJson('/api/v1/monitors', ['name' => 'a', 'type' => 'tcp', 'target' => 'example.com'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->postJson('/api/v1/monitors', ['name' => 'a', 'type' => 'tcp', 'target' => 'example.com:99999'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->postJson('/api/v1/monitors', ['name' => 'a', 'type' => 'ping', 'target' => 'bad host; rm'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->postJson('/api/v1/monitors', ['name' => 'a', 'type' => 'keyword', 'target' => 'https://example.com'])->assertUnprocessable()->assertJsonValidationErrors('keyword');
        $this->postJson('/api/v1/monitors', ['name' => 'a', 'type' => 'heartbeat', 'target' => 'https://example.com'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->postJson('/api/v1/monitors', ['name' => 'a', 'type' => 'bogus'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/v1/monitors', ['name' => 'a', 'type' => 'http', 'target' => 'https://example.com', 'interval_seconds' => 5])->assertUnprocessable()->assertJsonValidationErrors('interval_seconds');

        $this->postJson('/api/v1/monitors', ['name' => 'a', 'type' => 'tcp', 'target' => 'db.local:3306'])->assertCreated();
        $this->postJson('/api/v1/monitors', ['name' => 'b', 'type' => 'ssl', 'target' => 'example.com'])->assertCreated();
        $this->postJson('/api/v1/monitors', ['name' => 'c', 'type' => 'ping', 'target' => '10.0.0.1'])->assertCreated();
        $this->postJson('/api/v1/monitors', ['name' => 'd', 'type' => 'keyword', 'target' => 'https://example.com', 'keyword' => 'OK', 'expected_status' => 200])->assertCreated();
    }

    public function test_update_cannot_change_type(): void
    {
        $this->login();
        $monitor = Monitor::factory()->create();

        $this->putJson("/api/v1/monitors/{$monitor->id}", ['type' => 'tcp'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->putJson("/api/v1/monitors/{$monitor->id}", ['target' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('target');
        $this->patchJson("/api/v1/monitors/{$monitor->id}", ['name' => 'Новое', 'interval_seconds' => 120, 'failure_threshold' => 5])
            ->assertOk()
            ->assertJsonPath('data.name', 'Новое')
            ->assertJsonPath('data.interval_seconds', 120)
            ->assertJsonPath('data.failure_threshold', 5);
    }

    public function test_pause_and_resume(): void
    {
        $this->login();
        $monitor = Monitor::factory()->create(['consecutive_failures' => 2, 'next_check_at' => now()->addHour()]);

        $this->postJson("/api/v1/monitors/{$monitor->id}/pause")->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertSame(0, Monitor::due()->count());

        $this->postJson("/api/v1/monitors/{$monitor->id}/resume")->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.consecutive_failures', 0);
        $this->assertSame(1, Monitor::due()->count());
    }

    public function test_lists_filters_and_deletes_cascade(): void
    {
        $this->login();
        $up = Monitor::factory()->create(['name' => 'a', 'last_status' => 'up']);
        $down = Monitor::factory()->create(['name' => 'b', 'last_status' => 'down']);
        Monitor::factory()->ofType('tcp', 'h:1')->create(['name' => 'c']);
        $incident = Incident::create(['monitor_id' => $down->id, 'started_at' => now(), 'reason' => 'x']);
        MonitorCheck::create(['monitor_id' => $down->id, 'status' => 'down', 'checked_at' => now()]);

        $this->getJson('/api/v1/monitors')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/monitors?status=down')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.open_incident.id', $incident->id);
        $this->getJson('/api/v1/monitors?type=tcp')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/monitors/{$down->id}")->assertNoContent();
        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('monitor_checks', 0);
        $this->getJson("/api/v1/monitors/{$up->id}")->assertOk()->assertJsonStructure(['data' => ['uptime' => ['24h', '7d', '30d']]]);
    }

    public function test_checks_history_incidents_and_uptime(): void
    {
        $this->login();
        $monitor = Monitor::factory()->create();
        foreach ([['up', 1], ['up', 2], ['up', 3], ['down', 4]] as [$status, $hours]) {
            MonitorCheck::create(['monitor_id' => $monitor->id, 'status' => $status, 'checked_at' => now()->subHours($hours), 'response_time_ms' => 100]);
        }
        MonitorCheck::create(['monitor_id' => $monitor->id, 'status' => 'down', 'checked_at' => now()->subDays(3)]);
        MonitorCheck::create(['monitor_id' => $monitor->id, 'status' => 'up', 'checked_at' => now()->subDays(20)]);
        Incident::create(['monitor_id' => $monitor->id, 'started_at' => now()->subHour(), 'reason' => 'boom']);
        Incident::create(['monitor_id' => $monitor->id, 'started_at' => now()->subDay(), 'resolved_at' => now()->subHours(20), 'duration_seconds' => 4 * 3600]);

        $this->getJson("/api/v1/monitors/{$monitor->id}/checks")->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('data.0.status', 'up');
        $this->getJson("/api/v1/monitors/{$monitor->id}/checks?status=down")->assertOk()->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/monitors/{$monitor->id}/incidents")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'open')->assertJsonPath('data.1.duration_seconds', 14400);
        $this->getJson("/api/v1/monitors/{$monitor->id}/incidents?status=resolved")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/incidents?status=open')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.monitor.id', $monitor->id);
        $this->getJson('/api/v1/incidents/'.Incident::first()->id)->assertOk();

        $this->getJson("/api/v1/monitors/{$monitor->id}/uptime")->assertOk()
            ->assertJsonPath('data.24h.percent', 75)
            ->assertJsonPath('data.24h.checks', 4)
            ->assertJsonPath('data.7d.percent', 60)
            ->assertJsonPath('data.30d.checks', 6)
            ->assertJsonPath('data.30d.failed', 2);
    }

    public function test_uptime_is_null_without_checks(): void
    {
        $this->login();
        $monitor = Monitor::factory()->create();

        $this->getJson("/api/v1/monitors/{$monitor->id}/uptime")->assertOk()->assertJsonPath('data.24h.percent', null);
    }
}
