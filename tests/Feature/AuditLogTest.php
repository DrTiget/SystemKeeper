<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\ApiApplication;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use App\Services\Audit\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_changes_are_logged_with_masked_secrets(): void
    {
        $user = User::factory()->create(['name' => 'Иван']);
        Sanctum::actingAs($user);

        $id = $this->withHeaders(['User-Agent' => 'test-agent'])->postJson('/api/v1/servers', [
            'name' => 'web-1', 'host' => '10.0.0.5', 'username' => 'deploy', 'auth_type' => 'password', 'password' => 's3cret',
        ])->assertCreated()->json('data.id');

        $created = AuditLog::where('event', 'server.created')->sole();
        $this->assertSame(AuditLog::ACTOR_USER, $created->actor_type);
        $this->assertSame($user->id, $created->actor_id);
        $this->assertSame('Иван', $created->actor_name);
        $this->assertSame('server', $created->subject_type);
        $this->assertSame($id, $created->subject_id);
        $this->assertSame('web-1', $created->subject_label);
        $this->assertSame('test-agent', $created->user_agent);
        $this->assertSame('127.0.0.1', $created->ip);
        $this->assertSame('***', $created->changes['attributes']['password']);
        $this->assertSame('10.0.0.5', $created->changes['attributes']['host']);

        $this->patchJson("/api/v1/servers/{$id}", ['name' => 'web-2', 'password' => 'other'])->assertOk();
        $updated = AuditLog::where('event', 'server.updated')->sole();
        $this->assertSame(['name' => 'web-1', 'password' => '***'], $updated->changes['old']);
        $this->assertSame(['name' => 'web-2', 'password' => '***'], $updated->changes['new']);

        $this->deleteJson("/api/v1/servers/{$id}")->assertNoContent();
        $this->assertTrue(AuditLog::where('event', 'server.deleted')->where('subject_id', $id)->exists());

        $this->assertStringNotContainsString('s3cret', AuditLog::all()->toJson());
        $this->assertStringNotContainsString('other', AuditLog::pluck('changes')->toJson());
    }

    public function test_masking_is_recursive_by_key_name(): void
    {
        $masked = Audit::mask(['config' => ['bot_token' => '123:abc', 'chat_id' => '42', 'nested' => ['secret' => 'x', 'Password' => 'y']], 'api_key' => 'k', 'empty_password' => null]);

        $this->assertSame(['config' => ['bot_token' => '***', 'chat_id' => '42', 'nested' => ['secret' => '***', 'Password' => '***']], 'api_key' => '***', 'empty_password' => null], $masked);
    }

    public function test_login_logout_and_failed_login_are_logged(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => 'password1']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'password1'])->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(['auth.login_failed', 'auth.login', 'auth.logout'], AuditLog::orderBy('id')->pluck('event')->all());
        $failed = AuditLog::where('event', 'auth.login_failed')->sole();
        $this->assertSame(AuditLog::ACTOR_SYSTEM, $failed->actor_type);
        $this->assertSame('127.0.0.1', $failed->ip);
        $this->assertStringNotContainsString('wrong', $failed->toJson());
        $this->assertSame($user->id, AuditLog::where('event', 'auth.login')->sole()->actor_id);
    }

    public function test_system_events_for_backups_and_alerts(): void
    {
        $server = Server::factory()->create(['name' => 'db-1']);
        $backup = $server->backups()->create(['trigger' => Backup::TRIGGER_SCHEDULE, 'status' => Backup::QUEUED]);
        $backup->update(['status' => Backup::RUNNING]);
        $backup->update(['status' => Backup::FAILED, 'error' => 'нет места']);

        $this->assertSame(['backup.queued', 'backup.started', 'backup.failed'], AuditLog::orderBy('id')->pluck('event')->all());
        $failed = AuditLog::where('event', 'backup.failed')->sole();
        $this->assertSame(AuditLog::ACTOR_SYSTEM, $failed->actor_type);
        $this->assertSame('нет места', $failed->changes['error']);
        $this->assertSame('db-1 — бекап #'.$backup->id, $failed->subject_label);
        $this->assertNull($failed->ip);

        // изменения моделей фоновыми задачами (без актора) в журнал не пишутся
        $server->update(['name' => 'db-2']);
        $this->assertFalse(AuditLog::where('event', 'server.updated')->exists());

        $rule = AlertRule::create(['name' => 'CPU', 'kind' => 'cpu', 'severity' => AlertRule::WARNING, 'threshold' => 90, 'duration' => 5]);
        $alert = Alert::create(['alert_rule_id' => $rule->id, 'severity' => 'warning', 'status' => Alert::FIRING, 'object_type' => 'server', 'object_id' => $server->id, 'object_name' => 'db-2', 'message' => 'CPU 95%', 'started_at' => now()]);
        $alert->update(['status' => Alert::RESOLVED, 'resolved_at' => now()]);

        $this->assertSame('db-2', AuditLog::where('event', 'alert.fired')->sole()->subject_label);
        $this->assertTrue(AuditLog::where('event', 'alert.resolved')->exists());
    }

    public function test_actions_without_model_changes_are_logged_as_request(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->putJson('/api/v1/settings/notifications', ['mail' => ['host' => 'smtp.example.com', 'from_address' => 'sk@example.com', 'password' => 'smtp-secret']])->assertOk();

        $log = AuditLog::where('event', 'request')->sole();
        $this->assertSame('PUT', $log->changes['method']);
        $this->assertSame('/api/v1/settings/notifications', $log->changes['path']);
        $this->assertSame('***', $log->changes['input']['mail']['password']);
        $this->assertStringNotContainsString('smtp-secret', $log->toJson());

        // чтение не пишется
        $this->getJson('/api/v1/servers')->assertOk();
        $this->assertSame(1, AuditLog::count());
    }

    public function test_list_filters_pagination_and_access(): void
    {
        $user = User::factory()->create(['name' => 'Пётр']);
        $audit = app(Audit::class);
        $audit->record('server.created', Server::factory()->create(['name' => 'web']), [], $user);
        $audit->record('backup.failed', null, ['error' => 'x'], false);
        AuditLog::create(['actor_type' => 'system', 'event' => 'backup.success', 'created_at' => now()->subDays(10)]);

        $admin = User::factory()->admin()->create();

        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/audit-logs')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.event', 'backup.failed');
        $this->getJson('/api/v1/audit-logs?actor_type=user')->assertJsonPath('data.*.event', ['server.created']);
        $this->getJson("/api/v1/audit-logs?actor_type=user&actor_id={$user->id}")->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/audit-logs?event=backup.*')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/audit-logs?event=backup.failed')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/audit-logs?subject_type=server')->assertJsonPath('data.0.subject_label', 'web');
        $this->getJson('/api/v1/audit-logs?from='.now()->subDays(2)->toDateString())->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/audit-logs?to='.now()->subDays(5)->toDateString())->assertJsonPath('data.*.event', ['backup.success']);
        $this->getJson('/api/v1/audit-logs?search=Пётр')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/audit-logs?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/v1/audit-logs?actor_type=robot')->assertJsonValidationErrors('actor_type');

        $this->app['auth']->forgetGuards();
        $app = ApiApplication::create(['name' => 'SIEM', 'scopes' => ['audit:read']]);
        $this->withToken($app->issueToken())->getJson('/api/v1/audit-logs')->assertOk();
    }

    public function test_retention_setting_and_prune_command(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/settings/audit')->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/settings/audit')->assertOk()->assertJsonPath('data.retention_days', 90);
        $this->putJson('/api/v1/settings/audit', ['retention_days' => 0])->assertJsonValidationErrors('retention_days');
        $this->putJson('/api/v1/settings/audit', ['retention_days' => 30])->assertOk()->assertJsonPath('data.retention_days', 30);

        AuditLog::query()->delete();
        AuditLog::create(['actor_type' => 'system', 'event' => 'old', 'created_at' => now()->subDays(31)]);
        AuditLog::create(['actor_type' => 'system', 'event' => 'fresh', 'created_at' => now()->subDays(29)]);

        $this->artisan('audit:prune')->expectsOutput('Удалено записей журнала: 1')->assertSuccessful();
        $this->assertSame(['fresh'], AuditLog::pluck('event')->all());
    }
}
