<?php

namespace Tests\Feature;

use App\Exceptions\Ssh\SshConnectionException;
use App\Exceptions\Ssh\SshInternalException;
use App\Jobs\CollectServerMetrics;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerMetricPoint;
use App\Models\ServerMetrics;
use App\Models\User;
use App\Services\Metrics\MetricsCollector;
use App\Services\Ssh\SshConnectorInterface;
use App\Services\System\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeSshConnector;
use Tests\Support\MetricsSample;
use Tests\TestCase;

class ServerMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
    }

    private function fakeSsh(?string $stdout = null, ?SshConnectionException $throw = null): FakeSshConnector
    {
        $fake = new FakeSshConnector(['exit_code' => 0, 'stdout' => $stdout ?? MetricsSample::ubuntu(), 'stderr' => ''], $throw);
        $this->app->instance(SshConnectorInterface::class, $fake);

        return $fake;
    }

    private function collect(Server $server): ServerMetrics
    {
        return $this->app->make(MetricsCollector::class)->collect($server);
    }

    /** Поля ServerMetrics из frontend/src/api/metrics.ts */
    private const METRICS_STRUCTURE = [
        'server' => ['id', 'name', 'host', 'project'],
        'status', 'collected_at', 'error',
        'settings' => ['enabled', 'interval', 'retention_days', 'disks_exclude'],
        'cpu', 'cpu_cores', 'load', 'memory', 'swap', 'disks', 'network', 'uptime', 'os',
        'spark' => ['cpu', 'memory'],
    ];

    public function test_requires_auth(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/metrics/servers')->assertUnauthorized();
    }

    public function test_server_without_collection_has_default_settings(): void
    {
        $project = Project::factory()->create(['name' => 'Магазин']);
        $server = Server::factory()->create(['project_id' => $project->id]);

        // ровно как фронт: GET /servers/{id}/metrics
        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertOk()
            ->assertJsonStructure(['data' => self::METRICS_STRUCTURE])
            ->assertJsonPath('data.server.project', ['id' => $project->id, 'name' => 'Магазин'])
            ->assertJsonPath('data.status', 'stale')
            ->assertJsonPath('data.collected_at', null)
            ->assertJsonPath('data.settings', ['enabled' => true, 'interval' => 60, 'retention_days' => 30, 'disks_exclude' => []])
            ->assertJsonPath('data.cpu', null)
            ->assertJsonPath('data.disks', [])
            ->assertJsonPath('data.spark', ['cpu' => [], 'memory' => []]);
        // чтение ничего не создаёт
        $this->assertSame(0, ServerMetrics::count());
    }

    public function test_collect_stores_snapshot_and_point(): void
    {
        $this->travelTo(now()->startOfMinute());
        $server = Server::factory()->create();
        $fake = $this->fakeSsh();

        $this->collect($server);

        $this->assertStringContainsString('systemkeeper-metrics', $fake->calls[0]['command']);
        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertOk()
            ->assertJsonStructure(['data' => self::METRICS_STRUCTURE])
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.cpu', 27.6)
            ->assertJsonPath('data.cpu_cores', 4)
            ->assertJsonPath('data.load', [0.52, 0.61, 0.7])
            ->assertJsonPath('data.memory.total', 8_000_000 * 1024)
            ->assertJsonPath('data.swap.used', 500_000 * 1024)
            ->assertJsonPath('data.network', ['rx' => 200000, 'tx' => 10000])
            ->assertJsonPath('data.os', 'Ubuntu 24.04.1 LTS')
            ->assertJsonPath('data.disks.1.mount', '/data')
            ->assertJsonPath('data.disks.1.device', '/dev/sdb1')
            ->assertJsonCount(3, 'data.disks')
            ->assertJsonPath('data.spark.cpu', [27.6])
            ->assertJsonPath('data.spark.memory', [37.5]);

        $point = ServerMetricPoint::sole();
        $this->assertSame(37.5, $point->memory);
        $this->assertSame(25.0, $point->swap);
        $this->assertEquals(['/' => 41.0, '/data' => 90.0, '/boot/efi' => 5.8], $point->disks);
        $this->assertEquals(now()->addSeconds(60), ServerMetrics::sole()->next_collect_at);
    }

    public function test_internal_ssh_error_is_retried_once(): void
    {
        $server = Server::factory()->create();
        $fake = $this->fakeSsh();
        $fake->throwQueue = [new SshInternalException('Undefined array key 1')];

        $metrics = $this->collect($server);

        $this->assertCount(2, $fake->calls);
        $this->assertNull($metrics->error);
        $this->assertNotNull($metrics->collected_at);
        $this->assertSame(1, ServerMetricPoint::count());
    }

    public function test_repeated_internal_ssh_error_keeps_previous_state_without_error(): void
    {
        $this->travelTo(now()->startOfMinute());
        $server = Server::factory()->create();
        $this->fakeSsh();
        $this->collect($server);
        $collectedAt = ServerMetrics::sole()->collected_at;

        $this->travel(60)->seconds();
        $fake = $this->fakeSsh();
        $fake->throwQueue = [new SshInternalException('Undefined array key 1'), new SshInternalException('Undefined array key 1')];
        $metrics = $this->collect($server);

        $this->assertCount(2, $fake->calls);
        $this->assertNull($metrics->error);
        $this->assertEquals($collectedAt, $metrics->collected_at);
        $this->assertEquals(now(), $metrics->last_attempt_at);
        $this->assertEquals(now()->addSeconds(60), $metrics->next_collect_at);
        $this->assertSame(1, ServerMetricPoint::count());
        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertJsonPath('data.status', 'ok');

        // при долгом сбое данные устаревают, но «не отвечает» не появляется
        $this->travel(200)->seconds();
        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertJsonPath('data.status', 'stale')->assertJsonPath('data.error', null);
    }

    public function test_plain_ssh_error_is_not_retried_and_sets_error(): void
    {
        $server = Server::factory()->create();
        $fake = $this->fakeSsh(throw: new SshConnectionException('Connection timed out'));

        $metrics = $this->collect($server);

        $this->assertCount(1, $fake->calls);
        $this->assertSame('Connection timed out', $metrics->error);
    }

    public function test_status_stale_after_three_intervals_and_unreachable_on_error(): void
    {
        $this->travelTo(now()->startOfMinute());
        $server = Server::factory()->create();
        $this->fakeSsh();
        $this->collect($server);

        $this->travel(179)->seconds();
        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertJsonPath('data.status', 'ok');
        $this->travel(2)->seconds();
        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertJsonPath('data.status', 'stale');

        // сбор упал: статус «не отвечает», прошлые показатели и время сбора остаются
        $this->fakeSsh(throw: new SshConnectionException('Connection timed out'));
        $this->collect($server);
        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertOk()
            ->assertJsonPath('data.status', 'unreachable')
            ->assertJsonPath('data.error', 'Connection timed out')
            ->assertJsonPath('data.cpu', 27.6);
        $this->assertSame(1, ServerMetricPoint::count());

        // следующий удачный сбор снимает ошибку
        $this->fakeSsh();
        $this->collect($server);
        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertJsonPath('data.status', 'ok')->assertJsonPath('data.error', null);
    }

    public function test_garbage_output_is_unreachable(): void
    {
        $server = Server::factory()->create();
        $this->fakeSsh("sh: not linux\n");
        $this->collect($server);

        $this->getJson("/api/v1/servers/{$server->id}/metrics")
            ->assertJsonPath('data.status', 'unreachable')
            ->assertJsonPath('data.error', fn ($e) => str_contains($e, 'Linux'));
        $this->assertSame(0, ServerMetricPoint::count());
    }

    public function test_index_lists_all_servers_by_name_with_spark(): void
    {
        $b = Server::factory()->create(['name' => 'b-db']);
        $a = Server::factory()->create(['name' => 'a-web']);
        $this->fakeSsh();
        $this->collect($b);
        // точки за последний час и старше
        ServerMetricPoint::create(['server_id' => $b->id, 'created_at' => now()->subMinutes(30), 'cpu' => 10, 'memory' => 20]);
        ServerMetricPoint::create(['server_id' => $b->id, 'created_at' => now()->subHours(3), 'cpu' => 99, 'memory' => 99]);

        // ровно как фронт: GET /metrics/servers
        $response = $this->getJson('/api/v1/metrics/servers')->assertOk()
            ->assertJsonStructure(['data' => ['*' => self::METRICS_STRUCTURE]])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.server.id', $a->id)
            ->assertJsonPath('data.0.spark', ['cpu' => [], 'memory' => []])
            ->assertJsonPath('data.1.server.id', $b->id)
            ->assertJsonPath('data.1.spark.cpu', [10, 27.6]);
        $this->assertSame([20, 37.5], $response->json('data.1.spark.memory'));
    }

    public function test_history_returns_raw_points_for_hour_and_defaults_to_24h(): void
    {
        $server = Server::factory()->create();
        $other = Server::factory()->create();
        ServerMetricPoint::create(['server_id' => $server->id, 'created_at' => now()->subMinutes(50), 'cpu' => 10, 'memory' => 40, 'swap' => 1, 'load1' => 0.5, 'rx' => 100, 'tx' => 50, 'disks' => ['/' => 40.5]]);
        ServerMetricPoint::create(['server_id' => $server->id, 'created_at' => now()->subMinutes(10), 'cpu' => 20, 'memory' => 50, 'disks' => []]);
        ServerMetricPoint::create(['server_id' => $server->id, 'created_at' => now()->subHours(5), 'cpu' => 30]);
        ServerMetricPoint::create(['server_id' => $other->id, 'created_at' => now()->subMinutes(5), 'cpu' => 99]);

        // ровно как фронт: ?period=1h
        $this->getJson("/api/v1/servers/{$server->id}/metrics/history?period=1h")->assertOk()
            ->assertJsonStructure(['data' => ['*' => ['at', 'cpu', 'memory', 'swap', 'load1', 'rx', 'tx', 'disks']]])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.cpu', 10)
            ->assertJsonPath('data.0.disks', ['/' => 40.5])
            ->assertJsonPath('data.1.cpu', 20)
            ->assertJsonPath('data.1.swap', null);
        // пустые диски — объект, а не массив, как в Record<string, number>
        $this->assertStringContainsString('"disks":{}', $this->getJson("/api/v1/servers/{$server->id}/metrics/history?period=1h")->getContent());

        $this->getJson("/api/v1/servers/{$server->id}/metrics/history")->assertJsonCount(3, 'data');
        $this->getJson("/api/v1/servers/{$server->id}/metrics/history?period=1w")->assertUnprocessable()->assertJsonValidationErrors('period');
    }

    public function test_history_averages_long_periods_into_buckets(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(12));
        $server = Server::factory()->create();
        // 24h: интервал усреднения 5 минут. Три точки в одном, одна в другом
        $base = now()->subHours(2)->startOfHour();
        foreach ([[0, 10, '/data'], [1, 20, '/data'], [2, 30, '/'], [7, 50, '/']] as [$min, $cpu, $disk]) {
            ServerMetricPoint::create(['server_id' => $server->id, 'created_at' => $base->clone()->addMinutes($min), 'cpu' => $cpu, 'memory' => $cpu * 2, 'rx' => $cpu * 100, 'disks' => [$disk => $cpu]]);
        }

        foreach (['24h', '7d', '30d'] as $period) {
            $response = $this->getJson("/api/v1/servers/{$server->id}/metrics/history?period={$period}")->assertOk()
                ->assertJsonStructure(['data' => ['*' => ['at', 'cpu', 'memory', 'swap', 'load1', 'rx', 'tx', 'disks']]]);
            $this->assertLessThanOrEqual(310, count($response->json('data')), $period);
        }

        $data = $this->getJson("/api/v1/servers/{$server->id}/metrics/history?period=24h")->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(20.0, (float) $data[0]['cpu']);
        $this->assertSame(40.0, (float) $data[0]['memory']);
        $this->assertSame(2000, $data[0]['rx']);
        // заполненность дисков — по последней точке интервала
        $this->assertSame(['/' => 30], $data[0]['disks']);
        $this->assertSame(50.0, (float) $data[1]['cpu']);
        $this->assertSame($base->toIso8601String(), Carbon::parse($data[0]['at'])->toIso8601String());

        // за 7 дней оба интервала сливаются в один (интервал ~34 минуты)
        $this->assertCount(1, $this->getJson("/api/v1/servers/{$server->id}/metrics/history?period=7d")->json('data'));
    }

    public function test_settings_update_like_frontend(): void
    {
        $server = Server::factory()->create();
        ServerMetrics::create(['server_id' => $server->id, 'next_collect_at' => now()->addHour()]);

        // ровно как фронт: PUT с четырьмя полями
        $this->putJson("/api/v1/servers/{$server->id}/metrics/settings", ['enabled' => false, 'interval' => 120, 'retention_days' => 14, 'disks_exclude' => ['/snap', '/boot']])
            ->assertOk()
            ->assertJsonStructure(['data' => self::METRICS_STRUCTURE])
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.settings', ['enabled' => false, 'interval' => 120, 'retention_days' => 14, 'disks_exclude' => ['/snap', '/boot']]);

        $metrics = ServerMetrics::sole();
        $this->assertSame(120, $metrics->interval);
        $this->assertNull($metrics->next_collect_at);
    }

    public function test_settings_create_row_and_validate(): void
    {
        $server = Server::factory()->create();
        $url = "/api/v1/servers/{$server->id}/metrics/settings";

        $this->putJson($url, ['enabled' => true, 'interval' => 29, 'retention_days' => 30, 'disks_exclude' => []])->assertUnprocessable()->assertJsonValidationErrors('interval');
        $this->putJson($url, ['enabled' => true, 'interval' => 3601, 'retention_days' => 30, 'disks_exclude' => []])->assertUnprocessable()->assertJsonValidationErrors('interval');
        $this->putJson($url, ['enabled' => true, 'interval' => 60, 'retention_days' => 0, 'disks_exclude' => []])->assertUnprocessable()->assertJsonValidationErrors('retention_days');
        $this->putJson($url, ['enabled' => 'да', 'interval' => 60, 'retention_days' => 30, 'disks_exclude' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['enabled', 'disks_exclude']);
        $this->assertSame(0, ServerMetrics::count());

        $this->putJson($url, ['enabled' => true, 'interval' => 30, 'retention_days' => 365, 'disks_exclude' => []])->assertOk()->assertJsonPath('data.settings.interval', 30);
        $this->assertSame(1, ServerMetrics::count());
    }

    public function test_disks_exclude_applies_on_collect(): void
    {
        $server = Server::factory()->create();
        ServerMetrics::create(['server_id' => $server->id, 'disks_exclude' => ['/data']]);
        $this->fakeSsh();
        $this->collect($server);

        $this->getJson("/api/v1/servers/{$server->id}/metrics")->assertJsonCount(2, 'data.disks');
    }

    public function test_unknown_server_is_404(): void
    {
        $this->getJson('/api/v1/servers/999/metrics')->assertNotFound();
        $this->getJson('/api/v1/servers/999/metrics/history')->assertNotFound();
        $this->putJson('/api/v1/servers/999/metrics/settings', [])->assertNotFound();
    }

    public function test_dispatch_due_creates_settings_and_queues_due_servers(): void
    {
        Queue::fake();
        $new = Server::factory()->create();
        $due = Server::factory()->create();
        $later = Server::factory()->create();
        $off = Server::factory()->create();
        ServerMetrics::create(['server_id' => $due->id, 'next_collect_at' => now()->subSecond()]);
        ServerMetrics::create(['server_id' => $later->id, 'next_collect_at' => now()->addMinute()]);
        ServerMetrics::create(['server_id' => $off->id, 'enabled' => false]);

        $this->artisan('metrics:dispatch-due')->assertSuccessful();

        Queue::assertPushed(CollectServerMetrics::class, 2);
        Queue::assertPushed(CollectServerMetrics::class, fn ($j) => $j->serverId === $new->id);
        Queue::assertPushed(CollectServerMetrics::class, fn ($j) => $j->serverId === $due->id);
        $this->assertNotNull(ServerMetrics::where('server_id', $new->id)->value('next_collect_at'));

        // сервер занят на время сбора: повторный запуск ничего не ставит
        Queue::fake();
        $this->artisan('metrics:dispatch-due')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_dispatch_skipped_during_update(): void
    {
        Queue::fake();
        Server::factory()->create();
        $this->mock(Updater::class, fn ($m) => $m->shouldReceive('locked')->andReturn(true));

        $this->artisan('metrics:dispatch-due')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(0, ServerMetrics::count());
    }

    public function test_job_collects_server(): void
    {
        $server = Server::factory()->create();
        $this->fakeSsh();

        CollectServerMetrics::dispatchSync($server->id);

        $this->assertSame(1, ServerMetricPoint::count());
        // удалённый сервер — молча пропускаем
        CollectServerMetrics::dispatchSync(99999);
    }

    public function test_prune_uses_retention_of_each_server(): void
    {
        $short = Server::factory()->create();
        $long = Server::factory()->create();
        ServerMetrics::create(['server_id' => $short->id, 'retention_days' => 2]);
        ServerMetrics::create(['server_id' => $long->id, 'retention_days' => 30]);
        foreach ([$short, $long] as $s) {
            ServerMetricPoint::create(['server_id' => $s->id, 'created_at' => now()->subDays(5), 'cpu' => 1]);
            ServerMetricPoint::create(['server_id' => $s->id, 'created_at' => now()->subHour(), 'cpu' => 2]);
        }

        $this->artisan('metrics:prune')->assertSuccessful();

        $this->assertSame(1, ServerMetricPoint::where('server_id', $short->id)->count());
        $this->assertSame(2, ServerMetricPoint::where('server_id', $long->id)->count());
    }

    public function test_deleting_server_removes_metrics(): void
    {
        $server = Server::factory()->create();
        $this->fakeSsh();
        $this->collect($server);

        $this->deleteJson("/api/v1/servers/{$server->id}")->assertNoContent();

        $this->assertSame(0, ServerMetrics::count() + ServerMetricPoint::count());
    }
}
