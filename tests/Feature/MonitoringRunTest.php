<?php

namespace Tests\Feature;

use App\Events\IncidentOpened;
use App\Events\IncidentResolved;
use App\Jobs\RunMonitorCheck;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Services\Monitoring\NetworkProbe;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeNetworkProbe;
use Tests\TestCase;

class MonitoringRunTest extends TestCase
{
    use RefreshDatabase;

    private function probe(FakeNetworkProbe $probe = new FakeNetworkProbe): FakeNetworkProbe
    {
        $this->app->instance(NetworkProbe::class, $probe);

        return $probe;
    }

    private function runCheck(Monitor $monitor): void
    {
        RunMonitorCheck::dispatchSync($monitor->id);
    }

    public function test_http_monitor_up_and_down(): void
    {
        $monitor = Monitor::factory()->create(['target' => 'https://example.com/health']);

        Http::fake(['example.com/*' => Http::sequence()->push('ok', 200)->push('err', 503)]);

        $this->runCheck($monitor);
        $this->assertDatabaseHas('monitor_checks', ['monitor_id' => $monitor->id, 'status' => 'up', 'status_code' => 200]);

        $this->runCheck($monitor);
        $this->assertDatabaseHas('monitor_checks', ['monitor_id' => $monitor->id, 'status' => 'down', 'status_code' => 503]);
        $this->assertSame('down', $monitor->fresh()->last_status);
        $this->assertSame(1, $monitor->fresh()->consecutive_failures);
    }

    public function test_http_expected_status(): void
    {
        $monitor = Monitor::factory()->create(['expected_status' => 401]);
        Http::fake(['*' => Http::response('no', 401)]);

        $this->runCheck($monitor);

        $this->assertSame('up', $monitor->fresh()->last_status);
    }

    public function test_http_connection_error_is_recorded(): void
    {
        $monitor = Monitor::factory()->create();
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $this->runCheck($monitor);

        $check = MonitorCheck::first();
        $this->assertSame('down', $check->status);
        $this->assertStringContainsString('resolve host', $check->error);
    }

    public function test_keyword_monitor(): void
    {
        $monitor = Monitor::factory()->ofType('keyword', 'https://example.com')->create(['keyword' => 'Добро пожаловать']);
        Http::fake(['*' => Http::sequence()->push('<h1>Добро пожаловать</h1>', 200)->push('<h1>Error</h1>', 200)]);

        $this->runCheck($monitor);
        $this->assertSame('up', $monitor->fresh()->last_status);

        $this->runCheck($monitor);
        $this->assertSame('down', $monitor->fresh()->last_status);
        $this->assertStringContainsString('Добро пожаловать', MonitorCheck::latest('id')->first()->error);
    }

    public function test_tcp_and_ping_monitors(): void
    {
        $probe = $this->probe();
        $tcp = Monitor::factory()->ofType('tcp', 'db.local:3306')->create();
        $ping = Monitor::factory()->ofType('ping', '10.0.0.1')->create();

        $this->runCheck($tcp);
        $this->runCheck($ping);
        $this->assertSame(['tcp:db.local:3306', 'ping:10.0.0.1'], $probe->calls);
        $this->assertSame('up', $tcp->fresh()->last_status);
        $this->assertSame('up', $ping->fresh()->last_status);

        $probe->reachable = false;
        $this->runCheck($tcp);
        $this->runCheck($ping);
        $this->assertSame('down', $tcp->fresh()->last_status);
        $this->assertSame('down', $ping->fresh()->last_status);
        $this->assertSame('Соединение отклонено', MonitorCheck::where('monitor_id', $tcp->id)->latest('id')->first()->error);
    }

    public function test_ssl_monitor_checks_days_left(): void
    {
        $probe = $this->probe();
        $monitor = Monitor::factory()->ofType('ssl', 'https://example.com:8443')->create(['ssl_expiry_days' => 14]);

        $probe->sslExpiresAt = CarbonImmutable::now()->addDays(60);
        $this->runCheck($monitor);
        $this->assertSame('up', $monitor->fresh()->last_status);
        $this->assertSame(['ssl:example.com:8443'], $probe->calls);

        $probe->sslExpiresAt = CarbonImmutable::now()->addDays(5);
        $this->runCheck($monitor);
        $this->assertSame('down', $monitor->fresh()->last_status);
        $this->assertStringContainsString('осталось дней: 4', MonitorCheck::latest('id')->first()->error);

        $probe->sslExpiresAt = CarbonImmutable::now()->subDay();
        $this->runCheck($monitor);
        $this->assertStringContainsString('истёк', MonitorCheck::latest('id')->first()->error);
    }

    public function test_incident_opens_only_after_threshold_and_closes_on_first_success(): void
    {
        Event::fake([IncidentOpened::class, IncidentResolved::class]);
        $probe = $this->probe(new FakeNetworkProbe(reachable: false));
        $monitor = Monitor::factory()->ofType('tcp', 'h:80')->create(['failure_threshold' => 3]);

        $this->runCheck($monitor);
        $this->runCheck($monitor);
        $this->assertSame(0, Incident::count());
        Event::assertNotDispatched(IncidentOpened::class);

        $this->runCheck($monitor);
        $this->assertSame(1, Incident::count());
        Event::assertDispatchedTimes(IncidentOpened::class, 1);
        $incident = Incident::first();
        $this->assertNull($incident->resolved_at);
        $this->assertSame('Соединение отклонено', $incident->reason);

        // Дальнейшие неудачи не плодят инциденты
        $this->runCheck($monitor);
        $this->assertSame(1, Incident::count());
        Event::assertDispatchedTimes(IncidentOpened::class, 1);

        $this->travel(5)->minutes();
        $probe->reachable = true;
        $this->runCheck($monitor);

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame(300, $incident->duration_seconds);
        $this->assertSame(0, $monitor->fresh()->consecutive_failures);
        Event::assertDispatchedTimes(IncidentResolved::class, 1);
    }

    public function test_success_resets_failure_streak(): void
    {
        $probe = $this->probe(new FakeNetworkProbe(reachable: false));
        $monitor = Monitor::factory()->ofType('tcp', 'h:80')->create(['failure_threshold' => 3]);

        $this->runCheck($monitor);
        $this->runCheck($monitor);
        $probe->reachable = true;
        $this->runCheck($monitor);
        $probe->reachable = false;
        $this->runCheck($monitor);
        $this->runCheck($monitor);

        $this->assertSame(0, Incident::count());
    }

    public function test_threshold_of_one_opens_immediately(): void
    {
        $this->probe(new FakeNetworkProbe(reachable: false));
        $monitor = Monitor::factory()->ofType('tcp', 'h:80')->create(['failure_threshold' => 1]);

        $this->runCheck($monitor);

        $this->assertSame(1, Incident::count());
    }

    public function test_dispatch_command_queues_only_due_enabled_monitors(): void
    {
        Queue::fake();
        $due = Monitor::factory()->create();
        $elapsed = Monitor::factory()->create(['next_check_at' => now()->subSecond()]);
        Monitor::factory()->create(['next_check_at' => now()->addMinutes(5)]);
        Monitor::factory()->paused()->create();

        $this->artisan('monitors:dispatch')->assertSuccessful();

        Queue::assertPushed(RunMonitorCheck::class, 2);
        Queue::assertPushed(RunMonitorCheck::class, fn ($job) => $job->monitorId === $due->id);
        Queue::assertPushed(RunMonitorCheck::class, fn ($job) => $job->monitorId === $elapsed->id);

        // Повторный запуск в ту же минуту ничего не ставит
        Queue::fake();
        $this->artisan('monitors:dispatch')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertEquals(now()->addSeconds(60)->timestamp, $due->fresh()->next_check_at->timestamp);
    }

    public function test_job_skips_paused_monitor(): void
    {
        $monitor = Monitor::factory()->paused()->create();

        $this->runCheck($monitor);

        $this->assertSame(0, MonitorCheck::count());
    }

    public function test_dispatch_command_is_scheduled_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events());
        $event = $events->first(fn ($e) => str_contains($e->command, 'monitors:dispatch'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_prune_removes_old_checks(): void
    {
        $monitor = Monitor::factory()->create();
        MonitorCheck::create(['monitor_id' => $monitor->id, 'status' => 'up', 'checked_at' => now()->subDays(40)]);
        MonitorCheck::create(['monitor_id' => $monitor->id, 'status' => 'up', 'checked_at' => now()->subDays(10)]);

        $this->artisan('monitors:prune')->assertSuccessful();
        $this->assertSame(1, MonitorCheck::count());

        $this->artisan('monitors:prune --days=5')->assertSuccessful();
        $this->assertSame(0, MonitorCheck::count());
    }
}
