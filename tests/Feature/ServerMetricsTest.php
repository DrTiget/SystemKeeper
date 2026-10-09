<?php

namespace Tests\Feature;

use App\Events\MetricThresholdExceeded;
use App\Events\MetricThresholdRecovered;
use App\Exceptions\Ssh\SshConnectionException;
use App\Jobs\CollectServerMetrics;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\SshKey;
use App\Models\User;
use App\Services\Metrics\MetricsCollector;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\Support\MetricsFixtures;
use Tests\TestCase;

class ServerMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function fake(string $output): FakeSshConnector
    {
        $fake = new FakeSshConnector(['exit_code' => 0, 'stdout' => $output, 'stderr' => '']);
        $this->app->instance(SshConnectorInterface::class, $fake);

        return $fake;
    }

    private function collect(Server $server, string $output): ServerMetric
    {
        $this->fake($output);

        return $this->app->make(MetricsCollector::class)->collect($server->refresh());
    }

    public function test_collects_with_single_ssh_command_and_stores_metric(): void
    {
        $server = Server::factory()->create();
        $fake = $this->fake(MetricsFixtures::debian());

        $metric = $this->app->make(MetricsCollector::class)->collect($server);

        $this->assertCount(1, $fake->calls);
        $this->assertStringContainsString('/proc/loadavg', $fake->calls[0]['command']);
        $this->assertSame(1, $server->metrics()->count());
        $this->assertSame(4, $metric->cpu_count);
        $this->assertSame(25.0, $metric->memUsedPercent());
        $this->assertCount(2, $metric->disks);
        $this->assertNotNull($server->refresh()->metrics_collected_at);
        $this->assertNull($server->metrics_last_error);
    }

    public function test_failure_is_recorded_on_server(): void
    {
        $server = Server::factory()->create();
        $this->app->instance(SshConnectorInterface::class, new FakeSshConnector(throw: new SshConnectionException('Нет связи')));

        try {
            $this->app->make(MetricsCollector::class)->collect($server);
            $this->fail('Ожидалось исключение');
        } catch (SshConnectionException) {
        }

        $this->assertSame('Нет связи', $server->refresh()->metrics_last_error);
        $this->assertSame(0, $server->metrics()->count());
    }

    public function test_unparsable_output_is_recorded_as_error(): void
    {
        $server = Server::factory()->create();
        $this->fake("мусор\n");

        $this->expectException(\Throwable::class);
        try {
            $this->app->make(MetricsCollector::class)->collect($server);
        } finally {
            $this->assertNotNull($server->refresh()->metrics_last_error);
        }
    }

    public function test_exceeded_fires_only_after_confirmations_and_recovers_with_debounce(): void
    {
        Event::fake([MetricThresholdExceeded::class, MetricThresholdRecovered::class]);
        $server = Server::factory()->create(['threshold_disk_percent' => 90, 'threshold_confirmations' => 2]);
        $high = MetricsFixtures::debian('92000000', '8000000');   // 92 %
        $ok = MetricsFixtures::debian('30000000', '70000000');    // 30 %

        $this->collect($server, $high);
        Event::assertNotDispatched(MetricThresholdExceeded::class);   // один замер — ещё не событие

        $this->collect($server, $ok);                                  // всплеск прервался
        $this->collect($server, $high);
        Event::assertNotDispatched(MetricThresholdExceeded::class);

        $this->collect($server, $high);                                // второй подряд
        Event::assertDispatchedTimes(MetricThresholdExceeded::class, 1);
        Event::assertDispatched(MetricThresholdExceeded::class, fn ($e) => $e->metric === 'disk' && $e->target === '/'
            && $e->value === 92.0 && $e->threshold === 90.0 && $e->server->is($server));

        $this->collect($server, $high);                                // повтор не плодит события
        Event::assertDispatchedTimes(MetricThresholdExceeded::class, 1);

        $this->collect($server, $ok);                                  // один замер в норме — рано
        Event::assertNotDispatched(MetricThresholdRecovered::class);
        $this->collect($server, $high);                                // снова плохо — серия возврата сброшена
        $this->collect($server, $ok);
        Event::assertNotDispatched(MetricThresholdRecovered::class);

        $this->collect($server, $ok);
        Event::assertDispatchedTimes(MetricThresholdRecovered::class, 1);
        Event::assertDispatched(MetricThresholdRecovered::class, fn ($e) => $e->metric === 'disk' && $e->target === '/' && $e->exceededSince !== null);
        $this->assertSame(0, $server->metricStates()->count());
    }

    public function test_load_and_ram_thresholds(): void
    {
        Event::fake([MetricThresholdExceeded::class]);
        $server = Server::factory()->create(['threshold_load_per_core' => 1.5, 'threshold_ram_percent' => 80, 'threshold_confirmations' => 1]);

        // load5 = 8.0 на 4 ядра = 2.0 на ядро; память занята на 87.5 %
        $this->collect($server, MetricsFixtures::debian(load: '9.00 8.00 7.00 3/200 1', memAvailable: '1000000'));

        Event::assertDispatched(MetricThresholdExceeded::class, fn ($e) => $e->metric === 'load' && $e->target === null && $e->value === 2.0);
        Event::assertDispatched(MetricThresholdExceeded::class, fn ($e) => $e->metric === 'ram' && $e->value === 87.5);
        Event::assertNotDispatched(MetricThresholdExceeded::class, fn ($e) => $e->metric === 'disk');
    }

    public function test_null_threshold_disables_check_and_closes_open_exceedance(): void
    {
        Event::fake([MetricThresholdExceeded::class, MetricThresholdRecovered::class]);
        $server = Server::factory()->create(['threshold_confirmations' => 1]);

        $this->collect($server, MetricsFixtures::debian('95000000', '5000000'));
        Event::assertDispatchedTimes(MetricThresholdExceeded::class, 1);

        $server->update(['threshold_disk_percent' => null]);
        $this->collect($server, MetricsFixtures::debian('95000000', '5000000'));

        Event::assertDispatchedTimes(MetricThresholdRecovered::class, 1);
        $this->assertSame(0, $server->metricStates()->count());
    }

    public function test_vanished_mount_recovers(): void
    {
        Event::fake([MetricThresholdExceeded::class, MetricThresholdRecovered::class]);
        $server = Server::factory()->create(['threshold_confirmations' => 1]);

        $this->collect($server, MetricsFixtures::debian('95000000', '5000000'));
        $this->collect($server, MetricsFixtures::alpine());

        Event::assertDispatched(MetricThresholdRecovered::class, fn ($e) => $e->metric === 'disk' && $e->target === '/var/lib/my data' || $e->target === '/');
    }

    public function test_dispatch_command_queues_due_servers_once(): void
    {
        Queue::fake();
        $due = Server::factory()->create();
        $later = Server::factory()->create(['metrics_next_at' => now()->addMinutes(3)]);
        $off = Server::factory()->create(['metrics_enabled' => false]);
        $custom = Server::factory()->create(['metrics_interval_minutes' => 15]);

        $this->artisan('metrics:dispatch')->assertSuccessful();
        $this->artisan('metrics:dispatch')->assertSuccessful();

        Queue::assertPushed(CollectServerMetrics::class, 2);
        Queue::assertPushed(CollectServerMetrics::class, fn ($j) => $j->serverId === $due->id);
        Queue::assertPushed(CollectServerMetrics::class, fn ($j) => $j->serverId === $custom->id);
        $this->assertEqualsWithDelta(5, now()->diffInMinutes($due->refresh()->metrics_next_at), 0.1);
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($custom->refresh()->metrics_next_at), 0.1);
    }

    public function test_job_collects_and_swallows_errors(): void
    {
        $server = Server::factory()->create();
        $this->fake(MetricsFixtures::debian());
        (new CollectServerMetrics($server->id))->handle($this->app->make(MetricsCollector::class));
        $this->assertSame(1, $server->metrics()->count());

        $this->app->instance(SshConnectorInterface::class, new FakeSshConnector(throw: new SshConnectionException('boom')));
        (new CollectServerMetrics($server->id))->handle($this->app->make(MetricsCollector::class));
        $this->assertSame('boom', $server->refresh()->metrics_last_error);

        $server->update(['metrics_enabled' => false]);
        (new CollectServerMetrics($server->id))->handle($this->app->make(MetricsCollector::class));
        $this->assertSame(1, $server->metrics()->count());
    }

    public function test_prune_removes_old_metrics_only(): void
    {
        $server = Server::factory()->create();
        $this->fake(MetricsFixtures::debian());
        $collector = $this->app->make(MetricsCollector::class);
        $old = $collector->collect($server);
        $old->update(['collected_at' => now()->subDays(31)]);
        $new = $collector->collect($server);

        $this->artisan('metrics:prune')->assertSuccessful();

        $this->assertSame([$new->id], $server->metrics()->pluck('id')->all());
    }

    public function test_metrics_api_requires_authentication(): void
    {
        $server = Server::factory()->create();

        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertUnauthorized();
        $this->getJson("/api/v1/servers/{$server->id}/metrics/latest")->assertUnauthorized();
        $this->postJson("/api/v1/servers/{$server->id}/metrics/collect")->assertUnauthorized();
    }

    public function test_latest_returns_last_metric_and_active_alerts(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create(['threshold_confirmations' => 1]);

        $this->getJson("/api/v1/servers/{$server->id}/metrics/latest")
            ->assertOk()->assertJsonPath('data.metric', null)->assertJsonPath('data.alerts', []);

        $this->collect($server, MetricsFixtures::debian('95000000', '5000000'));

        $this->getJson("/api/v1/servers/{$server->id}/metrics/latest")
            ->assertOk()
            ->assertJsonPath('data.metric.load.load5', 0.4)
            ->assertJsonPath('data.metric.load.per_core', 0.1)
            ->assertJsonPath('data.metric.cpu_count', 4)
            ->assertJsonPath('data.metric.memory.used_percent', 25)
            ->assertJsonPath('data.metric.swap.used_percent', 25)
            ->assertJsonPath('data.metric.disks.0.mount', '/')
            ->assertJsonPath('data.metric.disks.0.used_percent', 95)
            ->assertJsonPath('data.metric.disks.0.inodes_total', 6400000)
            ->assertJsonPath('data.alerts.0.metric', 'disk')
            ->assertJsonPath('data.alerts.0.target', '/')
            ->assertJsonPath('data.alerts.0.threshold', 90);
    }

    public function test_history_aggregates_into_buckets(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();
        $base = now()->startOfHour()->subHours(2);
        foreach ([[0, '20000000', '80000000'], [5, '40000000', '60000000'], [65, '60000000', '40000000']] as [$min, $used, $avail]) {
            $this->fake(MetricsFixtures::debian($used, $avail));
            $this->app->make(MetricsCollector::class)->collect($server)->update(['collected_at' => $base->copy()->addMinutes($min)]);
        }

        // 3 часа / 3 точки = окна по часу
        $res = $this->getJson("/api/v1/servers/{$server->id}/metrics?".http_build_query([
            'from' => $base->toIso8601String(), 'to' => $base->copy()->addHours(3)->toIso8601String(), 'points' => 3,
        ]))->assertOk();

        $res->assertJsonPath('data.bucket_seconds', 3600)
            ->assertJsonCount(2, 'data.points')
            ->assertJsonPath('data.points.0.samples', 2)
            ->assertJsonPath('data.points.0.disks.0.mount', '/')
            ->assertJsonPath('data.points.0.disks.0.used_percent.avg', 30)
            ->assertJsonPath('data.points.0.disks.0.used_percent.max', 40)
            ->assertJsonPath('data.points.1.samples', 1)
            ->assertJsonPath('data.points.1.disks.0.used_percent.avg', 60)
            ->assertJsonPath('data.points.0.mem_used_percent.avg', 25);
    }

    public function test_history_validation(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();

        $this->getJson("/api/v1/servers/{$server->id}/metrics?from=2026-01-02&to=2026-01-01")->assertUnprocessable();
        $this->getJson("/api/v1/servers/{$server->id}/metrics?points=5000")->assertUnprocessable();
        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertOk()->assertJsonPath('data.points', []);
    }

    public function test_collect_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();

        $this->fake(MetricsFixtures::debian());
        $this->postJson("/api/v1/servers/{$server->id}/metrics/collect")
            ->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.metric.cpu_count', 4);

        $this->app->instance(SshConnectorInterface::class, new FakeSshConnector(throw: new SshConnectionException('Таймаут')));
        $this->postJson("/api/v1/servers/{$server->id}/metrics/collect")
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'Таймаут');
    }

    public function test_settings_in_server_api(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();

        $this->getJson("/api/v1/servers/{$server->id}")
            ->assertJsonPath('data.metrics_settings.enabled', true)
            ->assertJsonPath('data.metrics_settings.interval_minutes', 5)
            ->assertJsonPath('data.metrics_settings.confirmations', 2)
            ->assertJsonPath('data.metrics_settings.thresholds.disk_percent', 90)
            ->assertJsonPath('data.metrics_settings.thresholds.load_per_core', 2)
            ->assertJsonPath('data.metrics_settings.thresholds.ram_percent', 90);

        $this->putJson("/api/v1/servers/{$server->id}", ['metrics_settings' => [
            'interval_minutes' => 10, 'thresholds' => ['disk_percent' => 80, 'load_per_core' => 1.5, 'ram_percent' => null],
        ]])->assertOk()
            ->assertJsonPath('data.metrics_settings.interval_minutes', 10)
            ->assertJsonPath('data.metrics_settings.thresholds.disk_percent', 80)
            ->assertJsonPath('data.metrics_settings.thresholds.load_per_core', 1.5)
            ->assertJsonPath('data.metrics_settings.thresholds.ram_percent', null)
            ->assertJsonPath('data.metrics_settings.enabled', true);

        $this->putJson("/api/v1/servers/{$server->id}", ['metrics_settings' => ['enabled' => false]])
            ->assertOk()->assertJsonPath('data.metrics_settings.enabled', false)
            ->assertJsonPath('data.metrics_settings.thresholds.disk_percent', 80);
    }

    public function test_settings_validation(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $server = Server::factory()->create();

        $this->putJson("/api/v1/servers/{$server->id}", ['metrics_settings' => [
            'interval_minutes' => 0, 'confirmations' => 11, 'thresholds' => ['disk_percent' => 101, 'load_per_core' => 0, 'ram_percent' => 'x'],
        ]])->assertUnprocessable()->assertJsonValidationErrors([
            'metrics_settings.interval_minutes', 'metrics_settings.confirmations', 'metrics_settings.thresholds.disk_percent',
            'metrics_settings.thresholds.load_per_core', 'metrics_settings.thresholds.ram_percent',
        ]);
    }

    public function test_create_server_without_settings_returns_defaults(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $key = SshKey::factory()->create();

        $this->postJson('/api/v1/servers', [
            'name' => 'plain', 'host' => '10.0.0.8', 'username' => 'root', 'auth_type' => 'key', 'ssh_key_id' => $key->id,
        ])->assertCreated()
            ->assertJsonPath('data.metrics_settings.enabled', true)
            ->assertJsonPath('data.metrics_settings.interval_minutes', 5)
            ->assertJsonPath('data.metrics_settings.confirmations', 2)
            ->assertJsonPath('data.metrics_settings.thresholds.disk_percent', 90)
            ->assertJsonPath('data.metrics_settings.thresholds.load_per_core', 2)
            ->assertJsonPath('data.metrics_settings.thresholds.ram_percent', 90);
    }

    public function test_create_server_with_metrics_settings(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $key = SshKey::factory()->create();

        $this->postJson('/api/v1/servers', [
            'name' => 'db', 'host' => '10.0.0.9', 'username' => 'root', 'auth_type' => 'key', 'ssh_key_id' => $key->id,
            'metrics_settings' => ['interval_minutes' => 1, 'thresholds' => ['disk_percent' => 70]],
        ])->assertCreated()
            ->assertJsonPath('data.metrics_settings.interval_minutes', 1)
            ->assertJsonPath('data.metrics_settings.thresholds.disk_percent', 70);
    }

    public function test_deleting_server_removes_metrics(): void
    {
        $server = Server::factory()->create(['threshold_confirmations' => 1]);
        $this->collect($server, MetricsFixtures::debian('95000000', '5000000'));

        $server->delete();

        $this->assertSame(0, ServerMetric::count());
        $this->assertDatabaseCount('server_metric_states', 0);
    }
}
