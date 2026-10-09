<?php

namespace Tests\Feature;

use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Jobs\RunMonitorCheck;
use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function monitor(array $attrs = []): Monitor
    {
        return Monitor::factory()->heartbeat()->create($attrs + ['interval_seconds' => 300, 'heartbeat_grace_seconds' => 60, 'failure_threshold' => 1]);
    }

    public function test_ping_is_public_and_supports_get_and_post(): void
    {
        $monitor = $this->monitor();

        $this->getJson("/api/v1/heartbeat/{$monitor->heartbeat_token}")->assertOk()->assertJsonPath('data.ok', true);
        $first = $monitor->fresh()->last_heartbeat_at;
        $this->assertNotNull($first);

        $this->travel(10)->seconds();
        $this->postJson("/api/v1/heartbeat/{$monitor->heartbeat_token}")->assertOk();
        $this->assertTrue($monitor->fresh()->last_heartbeat_at->gt($first));
    }

    public function test_unknown_token_and_non_heartbeat_monitor_give_404(): void
    {
        $http = Monitor::factory()->create(['heartbeat_token' => 'abc']);

        $this->getJson('/api/v1/heartbeat/unknown')->assertNotFound();
        $this->getJson('/api/v1/heartbeat/abc')->assertNotFound();
    }

    public function test_paused_monitor_ignores_ping(): void
    {
        $monitor = $this->monitor(['enabled' => false]);

        $this->getJson("/api/v1/heartbeat/{$monitor->heartbeat_token}")->assertOk()->assertJsonPath('data.paused', true);
        $this->assertNull($monitor->fresh()->last_heartbeat_at);
    }

    public function test_monitor_is_up_within_interval_plus_grace(): void
    {
        $monitor = $this->monitor();
        $this->getJson("/api/v1/heartbeat/{$monitor->heartbeat_token}");

        $this->travel(359)->seconds();
        RunMonitorCheck::dispatchSync($monitor->id);

        $this->assertSame('up', $monitor->fresh()->last_status);
        $this->assertSame(0, Incident::count());
    }

    public function test_missing_ping_opens_incident_and_ping_closes_it(): void
    {
        Event::fake([IncidentOpened::class, IncidentResolved::class]);
        $monitor = $this->monitor();
        $this->getJson("/api/v1/heartbeat/{$monitor->heartbeat_token}");

        $this->travel(361)->seconds();
        RunMonitorCheck::dispatchSync($monitor->id);

        $this->assertSame('down', $monitor->fresh()->last_status);
        $this->assertSame(1, Incident::whereNull('resolved_at')->count());
        $this->assertStringContainsString('Heartbeat не поступал', Incident::first()->reason);
        Event::assertDispatched(IncidentOpened::class);

        $this->getJson("/api/v1/heartbeat/{$monitor->heartbeat_token}")->assertOk();

        $this->assertSame('up', $monitor->fresh()->last_status);
        $this->assertSame(0, Incident::whereNull('resolved_at')->count());
        Event::assertDispatched(IncidentResolved::class);
    }

    public function test_never_pinged_monitor_fails_after_window_from_creation(): void
    {
        $monitor = $this->monitor();

        $this->travel(200)->seconds();
        RunMonitorCheck::dispatchSync($monitor->id);
        $this->assertSame('up', $monitor->fresh()->last_status);

        $this->travel(200)->seconds();
        RunMonitorCheck::dispatchSync($monitor->id);
        $this->assertSame('down', $monitor->fresh()->last_status);
    }

    public function test_heartbeat_check_reschedules_to_window_end(): void
    {
        $monitor = $this->monitor();
        $this->getJson("/api/v1/heartbeat/{$monitor->heartbeat_token}");

        RunMonitorCheck::dispatchSync($monitor->id);

        $this->assertEqualsWithDelta(now()->addSeconds(361)->timestamp, $monitor->fresh()->next_check_at->timestamp, 2);
    }
}
