<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServerBackupSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create());
        $this->server = Server::factory()->create();
        Carbon::setTestNow('2026-10-13 10:00:00'); // UTC = 13:00 по Москве
    }

    private function url(): string
    {
        return "/api/v1/servers/{$this->server->id}/backup-settings";
    }

    public function test_defaults_when_not_configured(): void
    {
        $this->getJson($this->url())
            ->assertOk()
            ->assertExactJson(['data' => [
                'enabled' => false,
                'paths' => [],
                'excludes' => [],
                'schedule' => '0 3 * * *',
                'timezone' => 'Europe/Moscow',
                'next_run_at' => null,
                'pre_command' => null,
                'pre_command_timeout' => 300,
                'updated_at' => null,
            ]]);
        $this->assertDatabaseCount('server_backup_settings', 0);
    }

    public function test_save_and_next_run(): void
    {
        $this->putJson($this->url(), [
            'enabled' => true,
            'paths' => ['/var/www', '/etc/nginx'],
            'excludes' => ['*.log', '/var/www/cache'],
            'schedule' => '30 2 * * *',
            'pre_command' => "  cd /var/www && php artisan down  \n",
            'pre_command_timeout' => 600,
        ])->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.paths', ['/var/www', '/etc/nginx'])
            ->assertJsonPath('data.pre_command', 'cd /var/www && php artisan down')
            // следующий запуск — завтра в 02:30 по Москве
            ->assertJsonPath('data.next_run_at', '2026-10-14T02:30:00+03:00');

        $this->patchJson($this->url(), ['schedule' => '0 * * * *', 'pre_command' => ''])
            ->assertOk()
            ->assertJsonPath('data.paths', ['/var/www', '/etc/nginx'])
            ->assertJsonPath('data.pre_command', null)
            ->assertJsonPath('data.next_run_at', '2026-10-13T14:00:00+03:00');

        $this->assertDatabaseCount('server_backup_settings', 1);
    }

    public function test_server_resource_has_backup_summary(): void
    {
        $this->putJson($this->url(), ['enabled' => true, 'paths' => ['/srv']])->assertOk();

        $this->getJson('/api/v1/servers')
            ->assertJsonPath('data.0.backup.enabled', true)
            ->assertJsonPath('data.0.backup.paths_count', 1)
            ->assertJsonPath('data.0.backup.schedule', '0 3 * * *');
        $this->getJson("/api/v1/servers/{$this->server->id}")->assertJsonPath('data.backup.next_run_at', '2026-10-14T03:00:00+03:00');
    }

    public function test_validation(): void
    {
        $this->putJson($this->url(), ['enabled' => true])
            ->assertJsonValidationErrors(['paths' => 'Чтобы включить бекап, укажите хотя бы один каталог.']);

        $response = $this->putJson($this->url(), [
            'paths' => ['relative/dir', '/ok', '/ok', "/bad\nline"],
            'schedule' => 'every day',
            'pre_command_timeout' => 5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['paths.0', 'paths.1', 'paths.3', 'schedule', 'pre_command_timeout']);

        $this->assertSame('Каталог должен быть абсолютным путём (начинаться с /).', $response->json('errors')['paths.0'][0]);
        $this->assertStringStartsWith('Неверное расписание', $response->json('errors.schedule.0'));
    }

    public function test_settings_deleted_with_server(): void
    {
        $this->putJson($this->url(), ['paths' => ['/srv']])->assertOk();

        $this->deleteJson("/api/v1/servers/{$this->server->id}")->assertNoContent();

        $this->assertDatabaseCount('server_backup_settings', 0);
    }
}
