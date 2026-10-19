<?php

namespace Tests\Feature;

use App\Jobs\CheckMonitor;
use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use App\Models\MonitorIncident;
use App\Services\Monitoring\CheckResult;
use App\Services\Monitoring\MonitorChecker;
use App\Services\Monitoring\MonitorRecorder;
use App\Services\Monitoring\NetworkMonitorChecker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeMonitorChecker;
use Tests\TestCase;

class MonitorCheckTest extends TestCase
{
    use RefreshDatabase;

    private function run_job(Monitor $m, CheckResult ...$results): void
    {
        (new CheckMonitor($m))->handle(new FakeMonitorChecker($results), app(MonitorRecorder::class));
    }

    public function test_retries_then_down_opens_incident_and_recovery_closes_it(): void
    {
        $m = Monitor::factory()->create(['retries' => 1, 'interval' => 300, 'retry_interval' => 30]);
        $fail = CheckResult::down('timeout', null);

        $this->run_job($m, $fail);
        $m->refresh();
        $this->assertSame('pending', $m->status);
        $this->assertSame(1, $m->failures);
        $this->assertEqualsWithDelta(30, now()->diffInSeconds($m->next_check_at, false), 2);
        $this->assertDatabaseCount('monitor_incidents', 0);

        $this->run_job($m, $fail);
        $m->refresh();
        $this->assertSame('down', $m->status);
        $this->assertSame('timeout', $m->last_error);
        $this->assertEqualsWithDelta(300, now()->diffInSeconds($m->next_check_at, false), 2);
        $this->assertDatabaseHas('monitor_incidents', ['monitor_id' => $m->id, 'ended_at' => null, 'error' => 'timeout']);

        // повторная неудача не открывает второй сбой
        $this->run_job($m, $fail);
        $this->assertDatabaseCount('monitor_incidents', 1);

        $this->run_job($m, CheckResult::up(50, '200 OK', 200));
        $m->refresh();
        $this->assertSame('up', $m->status);
        $this->assertSame(0, $m->failures);
        $this->assertNull($m->last_error);
        $this->assertNotNull(MonitorIncident::first()->ended_at);
        $this->assertSame(['pending', 'down', 'down', 'up'], MonitorHeartbeat::orderBy('id')->pluck('status')->all());
    }

    public function test_status_since_changes_only_with_status(): void
    {
        $m = Monitor::factory()->create();
        $this->run_job($m, CheckResult::up(10));
        $since = $m->refresh()->status_since;
        $this->assertNotNull($since);

        Carbon::setTestNow(now()->addMinutes(5));
        $this->run_job($m, CheckResult::up(10));
        $this->assertEquals($since, $m->refresh()->status_since);

        Carbon::setTestNow(now()->addMinutes(5));
        $m->update(['retries' => 0]);
        $this->run_job($m, CheckResult::down('x'));
        $this->assertTrue($m->refresh()->status_since->greaterThan($since));
    }

    public function test_job_skips_disabled_monitor(): void
    {
        $m = Monitor::factory()->create(['enabled' => false]);
        $this->run_job($m, CheckResult::up(10));
        $this->assertDatabaseCount('monitor_heartbeats', 0);
    }

    public function test_dispatch_due_claims_only_due_enabled_monitors(): void
    {
        Queue::fake();
        $new = Monitor::factory()->create();
        $due = Monitor::factory()->create(['next_check_at' => now()->subSecond()]);
        Monitor::factory()->create(['next_check_at' => now()->addMinute()]);
        Monitor::factory()->create(['enabled' => false]);

        $this->artisan('monitors:dispatch-due')->assertSuccessful();
        Queue::assertPushed(CheckMonitor::class, 2);
        Queue::assertPushed(CheckMonitor::class, fn ($j) => $j->monitor->is($new) || $j->monitor->is($due));

        // второй запуск планировщика ничего не добавляет: мониторы заняты
        $this->artisan('monitors:dispatch-due')->assertSuccessful();
        Queue::assertPushed(CheckMonitor::class, 2);
    }

    public function test_prune_removes_old_heartbeats_only(): void
    {
        $m = Monitor::factory()->create();
        MonitorHeartbeat::create(['monitor_id' => $m->id, 'status' => 'up', 'created_at' => now()->subDays(31)]);
        MonitorHeartbeat::create(['monitor_id' => $m->id, 'status' => 'up', 'created_at' => now()->subDays(29)]);

        $this->artisan('monitors:prune')->assertSuccessful();

        $this->assertDatabaseCount('monitor_heartbeats', 1);
    }

    public function test_scheduler_has_monitor_commands(): void
    {
        $commands = collect(app(Schedule::class)->events())->map->command->implode("\n");
        $this->assertStringContainsString('monitors:dispatch-due', $commands);
        $this->assertStringContainsString('monitors:prune', $commands);
    }

    public function test_real_checker_is_bound_by_default(): void
    {
        $this->assertInstanceOf(NetworkMonitorChecker::class, app(MonitorChecker::class));
    }

    // --- NetworkMonitorChecker (сети нет: Http::fake и подмена сокетов) ---

    private function http(array $attrs = []): Monitor
    {
        return Monitor::factory()->make(['ssl_expiry_days' => null, ...$attrs]);
    }

    public function test_http_status_and_keyword(): void
    {
        Http::fake(['*' => Http::response('Hello world', 200)]);
        $checker = new NetworkMonitorChecker;

        $r = $checker->check($this->http());
        $this->assertTrue($r->ok);
        $this->assertSame(200, $r->statusCode);
        $this->assertSame('200 OK', $r->message);

        $this->assertTrue($checker->check($this->http(['keyword' => 'world']))->ok);
        $this->assertSame('нет слова на странице', $checker->check($this->http(['keyword' => 'nope']))->message);
        $this->assertFalse($checker->check($this->http(['keyword' => 'world', 'keyword_invert' => true]))->ok);
        $this->assertTrue($checker->check($this->http(['keyword' => 'nope', 'keyword_invert' => true]))->ok);
    }

    public function test_http_accepted_statuses(): void
    {
        Http::fake(['*' => Http::response('x', 404)]);
        $checker = new NetworkMonitorChecker;

        $r = $checker->check($this->http());
        $this->assertFalse($r->ok);
        $this->assertSame(404, $r->statusCode);
        $this->assertTrue($checker->check($this->http(['accepted_statuses' => ['200-299', '404']]))->ok);
        $this->assertTrue($checker->check($this->http(['accepted_statuses' => ['400-499']]))->ok);
    }

    public function test_http_sends_method_headers_and_body_without_leaking_them(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $r = (new NetworkMonitorChecker)->check($this->http([
            'method' => 'POST', 'body' => 'payload', 'headers' => [['name' => 'X-Token', 'value' => 'top-secret']],
        ]));

        Http::assertSent(fn ($req) => $req->method() === 'POST' && $req->header('X-Token') === ['top-secret'] && $req->body() === 'payload');
        $this->assertFalse($r->ok);
        $this->assertStringNotContainsString('top-secret', (string) $r->message);
    }

    public function test_http_connection_error_is_down_without_credentials(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to https://user:pass@example.com/x'));

        $r = (new NetworkMonitorChecker)->check($this->http(['url' => 'https://user:pass@example.com/x']));

        $this->assertFalse($r->ok);
        $this->assertStringNotContainsString('pass', $r->message);
        $this->assertStringContainsString('Failed to connect', $r->message);
    }

    public function test_http_ssl_expiry(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $cert = fn (Carbon $to) => new class($to) extends NetworkMonitorChecker
        {
            public function __construct(private Carbon $to) {}

            protected function certificate(string $url, int $timeout): ?array
            {
                return ['valid_to' => $this->to, 'issuer' => 'Test CA'];
            }
        };

        $r = $cert(now()->addDays(60))->check($this->http(['ssl_expiry_days' => 14]));
        $this->assertTrue($r->ok);
        $this->assertSame('Test CA', $r->sslIssuer);
        $this->assertNotNull($r->sslValidTo);
        $this->assertSame('200 OK', $r->message);

        $soon = $cert(now()->addDays(5))->check($this->http(['ssl_expiry_days' => 14]));
        $this->assertTrue($soon->ok);
        $this->assertStringContainsString('сертификат истекает через', $soon->message);

        $expired = $cert(now()->subDays(2))->check($this->http(['ssl_expiry_days' => 14]));
        $this->assertFalse($expired->ok);
        $this->assertStringContainsString('сертификат истёк', $expired->message);
        $this->assertTrue($cert(now()->subDays(2))->check($this->http(['ssl_expiry_days' => 14, 'ignore_tls' => true]))->ok);
        // http-адрес — сертификата нет
        $this->assertNull($cert(now())->check($this->http(['url' => 'http://example.com', 'ssl_expiry_days' => 14]))->sslValidTo);
    }

    public function test_http_ignore_tls_and_redirect_options(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $seen = [];
        Http::globalMiddleware(function ($handler) use (&$seen) {
            return function ($request, array $options) use ($handler, &$seen) {
                $seen = $options;

                return $handler($request, $options);
            };
        });

        (new NetworkMonitorChecker)->check($this->http(['ignore_tls' => true, 'follow_redirects' => false]));

        $this->assertFalse($seen['verify']);
        $this->assertFalse($seen['allow_redirects']);
    }

    public function test_tcp(): void
    {
        $checker = fn (?string $error) => new class($error) extends NetworkMonitorChecker
        {
            public function __construct(private ?string $error) {}

            protected function tcpConnect(string $host, int $port, int $timeout): ?string
            {
                return $this->error;
            }
        };
        $m = Monitor::factory()->tcp(5432)->make();

        $this->assertTrue($checker(null)->check($m)->ok);
        $r = $checker('Connection refused')->check($m);
        $this->assertFalse($r->ok);
        $this->assertSame('Connection refused', $r->message);
    }

    public function test_dns(): void
    {
        $checker = new class extends NetworkMonitorChecker
        {
            public array $values = ['93.184.216.34'];

            protected function dnsLookup(string $host, string $type, ?string $resolver, int $timeout): array
            {
                return $this->values;
            }
        };
        $m = Monitor::factory()->make(['type' => 'dns', 'hostname' => 'example.com', 'dns_record_type' => 'A']);

        $this->assertTrue($checker->check($m)->ok);
        $m->dns_expected = '93.184.216.34';
        $this->assertTrue($checker->check($m)->ok);
        $m->dns_expected = '1.1.1.1';
        $this->assertStringContainsString('не совпало', $checker->check($m)->message);
        $checker->values = [];
        $this->assertFalse($checker->check($m)->ok);
    }

    public function test_ping_uses_process(): void
    {
        Process::fake(['*' => Process::result("64 bytes from x: icmp_seq=1 ttl=57 time=12.4 ms\n")]);
        $m = Monitor::factory()->make(['type' => 'ping', 'hostname' => '8.8.8.8']);

        $r = (new NetworkMonitorChecker)->check($m);

        $this->assertTrue($r->ok);
        $this->assertSame(12, $r->responseMs);

        Process::fake(['*' => Process::result('', '', 1)]);
        $this->assertFalse((new NetworkMonitorChecker)->check($m)->ok);
    }
}
