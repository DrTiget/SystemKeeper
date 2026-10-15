<?php

namespace Tests\Feature;

use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupHook;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackupHookTest extends TestCase
{
    use RefreshDatabase;

    private function server(array $paths = ['/var/www']): Server
    {
        $server = Server::factory()->create();
        $server->backupSettings()->create([
            'enabled' => false, 'paths' => $paths, 'excludes' => [], 'schedule' => '0 3 * * *',
            'pre_command' => null, 'pre_command_timeout' => 60, 'pre_command_continue_on_error' => false,
        ]);

        return $server;
    }

    /** Включает хук от имени пользователя и возвращает его адрес; дальше запросы идут без входа. */
    private function enable(string $source): string
    {
        Sanctum::actingAs(User::factory()->create());
        $path = $this->postJson("/api/v1/$source/backup-hook")->assertCreated()->assertJsonPath('data.enabled', true)->json('data.path');
        $this->app['auth']->forgetGuards();

        return $path;
    }

    public function test_hook_starts_server_backup_without_login(): void
    {
        Queue::fake();
        $server = $this->server();
        $path = $this->enable("servers/{$server->id}");
        $this->assertMatchesRegularExpression('#^/api/v1/hooks/skh_[A-Za-z0-9]{40}$#', $path);

        $response = $this->postJson($path)->assertStatus(202)->assertJsonPath('data.status', 'queued');

        $backup = Backup::sole();
        $this->assertSame(Backup::TRIGGER_HOOK, $backup->trigger);
        $this->assertNull($backup->user_id);
        $this->assertTrue($backup->source->is($server));
        Queue::assertPushed(RunBackup::class);
        $this->assertNotNull(BackupHook::sole()->last_used_at);

        // ход бекапа — по status_url, тоже без входа
        $statusUrl = $response->json('data.status_url');
        $this->assertStringEndsWith("$path/backups/{$backup->id}", $statusUrl);
        $backup->update(['status' => Backup::SUCCESS, 'size' => 123]);
        $this->getJson($statusUrl)->assertOk()->assertJsonPath('data.status', 'success')->assertJsonPath('data.size', 123);

        // второй запуск, пока первый в очереди, — 409
        $backup->update(['status' => Backup::RUNNING]);
        $this->postJson($path)->assertStatus(409);
    }

    public function test_hook_starts_database_backup(): void
    {
        Queue::fake();
        $db = DatabaseConnection::factory()->create();
        $path = $this->enable("databases/{$db->id}");

        $this->postJson($path)->assertStatus(202);
        $this->assertTrue(Backup::sole()->source->is($db));
    }

    public function test_status_shows_only_backups_of_hook_source(): void
    {
        Queue::fake();
        $server = $this->server();
        $other = Backup::make(['trigger' => Backup::TRIGGER_MANUAL, 'status' => Backup::SUCCESS]);
        $other->source()->associate($this->server())->save();
        $path = $this->enable("servers/{$server->id}");

        $this->getJson("$path/backups/{$other->id}")->assertNotFound();
    }

    public function test_regenerated_or_disabled_hook_stops_working(): void
    {
        Queue::fake();
        $server = $this->server();
        $old = $this->enable("servers/{$server->id}");
        $new = $this->enable("servers/{$server->id}");

        $this->assertNotSame($old, $new);
        $this->postJson($old)->assertNotFound();
        $this->assertSame(1, BackupHook::count());

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/servers/{$server->id}/backup-hook")->assertOk()->assertJsonPath('data.path', $new);
        $this->deleteJson("/api/v1/servers/{$server->id}/backup-hook")->assertNoContent();
        $this->getJson("/api/v1/servers/{$server->id}/backup-hook")->assertJsonPath('data.enabled', false)->assertJsonPath('data.path', null);
        $this->app['auth']->forgetGuards();

        $this->postJson($new)->assertNotFound();
        $this->postJson('/api/v1/hooks/skh_unknown')->assertNotFound();
        $this->assertSame(0, Backup::count());
    }

    public function test_hook_rejects_server_without_paths_and_is_removed_with_server(): void
    {
        $server = $this->server([]);
        $path = $this->enable("servers/{$server->id}");

        $this->postJson($path)->assertStatus(422)->assertJsonValidationErrors('paths');

        $server->delete();
        $this->assertSame(0, BackupHook::count());
    }

    public function test_token_is_stored_encrypted(): void
    {
        $server = $this->server();
        $path = $this->enable("servers/{$server->id}");
        $token = basename($path);

        $raw = BackupHook::query()->toBase()->sole();
        $this->assertStringNotContainsString($token, $raw->token);
        $this->assertSame(hash('sha256', $token), $raw->token_hash);
    }
}
