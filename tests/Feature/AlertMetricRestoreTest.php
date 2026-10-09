<?php

namespace Tests\Feature;

use App\Events\BackupRestoreFailed;
use App\Events\MetricThresholdExceeded;
use App\Events\MetricThresholdRecovered;
use App\Jobs\SendAlertDelivery;
use App\Models\AlertChannel;
use App\Models\AlertDelivery;
use App\Models\AlertRule;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertMetricRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function rule(string $event, array $attrs = []): AlertRule
    {
        $rule = AlertRule::factory()->create(['event' => $event] + $attrs);
        $rule->channels()->sync([AlertChannel::factory()->create()->id]);

        return $rule;
    }

    private function sample(Server $server): ServerMetric
    {
        return ServerMetric::create([
            'server_id' => $server->id, 'collected_at' => now(), 'load1' => 1, 'load5' => 1, 'load15' => 1,
            'mem_total' => 100, 'mem_used' => 90, 'mem_available' => 10, 'disks' => [],
        ]);
    }

    public function test_metric_exceeded_and_recovered_messages(): void
    {
        Queue::fake([SendAlertDelivery::class]);
        config(['alerts.frontend_url' => 'https://keeper.example.com']);
        $server = Server::factory()->create(['name' => 'web-1']);
        $this->rule('metric.threshold_exceeded');
        $this->rule('metric.threshold_recovered');
        $sample = $this->sample($server);

        event(new MetricThresholdExceeded($server, $sample, 'disk', '/var', 91.5, 90.0));
        event(new MetricThresholdRecovered($server, $sample, 'disk', '/var', 70.0, 90.0, now()->subHour()));

        $first = AlertDelivery::where('event', 'metric.threshold_exceeded')->first();
        $this->assertSame('🟠 Диск /var на сервере «web-1» выше порога', $first->title);
        $this->assertSame('Значение: 91.5%, порог: 90%.', $first->body);
        $this->assertSame("https://keeper.example.com/servers/{$server->id}", $first->url);
        $this->assertSame("server:{$server->id}:disk:/var", $first->object_key);

        $second = AlertDelivery::where('event', 'metric.threshold_recovered')->first();
        $this->assertSame('🟢 Диск /var на сервере «web-1» в норме', $second->title);
        $this->assertStringContainsString('Превышение было с', $second->body);
    }

    public function test_metric_filter_by_server_and_throttle_per_metric(): void
    {
        Queue::fake([SendAlertDelivery::class]);
        $a = Server::factory()->create();
        $b = Server::factory()->create();
        $this->rule('metric.threshold_exceeded', ['target_type' => 'server', 'target_id' => $a->id, 'throttle_minutes' => 10]);

        event(new MetricThresholdExceeded($b, $this->sample($b), 'ram', null, 95, 90));
        $this->assertSame(0, AlertDelivery::count());

        $sample = $this->sample($a);
        event(new MetricThresholdExceeded($a, $sample, 'ram', null, 95, 90));
        event(new MetricThresholdExceeded($a, $sample, 'ram', null, 96, 90));
        event(new MetricThresholdExceeded($a, $sample, 'load', null, 3.5, 2));

        $this->assertSame(2, AlertDelivery::where('status', 'pending')->count());
        $this->assertSame(1, AlertDelivery::where('status', 'skipped')->count());
        $this->assertStringContainsString('Память', AlertDelivery::first()->title);
        $this->assertStringContainsString('Нагрузка', AlertDelivery::orderByDesc('id')->first()->title);
    }

    public function test_backup_restore_failed_alert(): void
    {
        Queue::fake([SendAlertDelivery::class]);
        config(['alerts.frontend_url' => 'https://keeper.example.com']);
        $job = BackupJob::factory()->create(['name' => 'Дамп БД']);
        $other = BackupJob::factory()->create();
        $this->rule('backup.restore_failed', ['target_type' => 'backup_job', 'target_id' => $job->id]);
        $backup = Backup::factory()->create(['backup_job_id' => $job->id, 'status' => 'success']);
        $backup->forceFill(['restore_status' => 'failed', 'restore_log' => str_repeat('x', 500)])->save();

        event(new BackupRestoreFailed(Backup::factory()->create(['backup_job_id' => $other->id, 'status' => 'success'])));
        $this->assertSame(0, AlertDelivery::count());

        event(new BackupRestoreFailed($backup));

        $delivery = AlertDelivery::first();
        $this->assertSame('🔴 Тестовое восстановление бекапа «Дамп БД» не удалось', $delivery->title);
        $this->assertLessThanOrEqual(320, mb_strlen($delivery->body));
        $this->assertStringStartsWith('Причина: xxx', $delivery->body);
        $this->assertSame('https://keeper.example.com/backups', $delivery->url);
    }

    public function test_rules_api_accepts_new_events_and_rejects_legacy_one(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $channel = AlertChannel::factory()->create();

        foreach (['metric.threshold_exceeded', 'metric.threshold_recovered', 'backup.restore_failed'] as $event) {
            $this->postJson('/api/v1/alert-rules', ['name' => $event, 'event' => $event, 'channel_ids' => [$channel->id]])->assertCreated();
        }
        $this->postJson('/api/v1/alert-rules', ['name' => 'x', 'event' => 'metric.threshold', 'channel_ids' => [$channel->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('event');
    }
}
