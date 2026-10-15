<?php

namespace Tests\Feature;

use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupStorage;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemUpdateTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/sk-updater-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['systemkeeper.updater_path' => $this->dir, 'systemkeeper.version' => 'v1.0.0']);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function release(string $tag = 'v1.1.0'): array
    {
        // GET /releases — список; черновики и пре-релизы не считаются
        return [
            ['tag_name' => 'v0.9.0', 'name' => '', 'body' => '- старое', 'html_url' => 'https://github.com/x/y/releases/tag/v0.9.0', 'published_at' => '2026-10-01T10:00:00Z'],
            [
                'tag_name' => $tag, 'name' => "SystemKeeper $tag", 'body' => '- новое', 'html_url' => "https://github.com/x/y/releases/tag/$tag",
                'published_at' => '2026-10-14T10:00:00Z',
            ],
            ['tag_name' => 'v99.0.0', 'draft' => true, 'name' => '', 'body' => '', 'html_url' => '', 'published_at' => null],
            ['tag_name' => 'v98.0.0-rc1', 'name' => '', 'body' => '', 'html_url' => '', 'published_at' => null],
        ];
    }

    private function fakeRelease(string $tag = 'v1.1.0'): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->release($tag), 200, ['ETag' => '"abc"'])]);
    }

    public function test_check_finds_newer_release(): void
    {
        $this->fakeRelease();
        $this->artisan('system:check-updates')->assertSuccessful();

        $this->getJson('/api/v1/system/version')
            ->assertOk()
            ->assertJsonPath('data.current', 'v1.0.0')
            ->assertJsonPath('data.latest.version', 'v1.1.0')
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.updater.available', false)
            ->assertJsonPath('data.updater.status', 'idle');
    }

    public function test_repeat_check_sends_etag_and_keeps_release_on_304(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()
            ->push($this->release(), 200, ['ETag' => '"abc"'])
            ->push(null, 304)]);
        $this->artisan('system:check-updates');
        $this->artisan('system:check-updates');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->header('If-None-Match') === ['"abc"']);
        $this->getJson('/api/v1/system/version')->assertJsonPath('data.latest.version', 'v1.1.0');
    }

    public function test_same_or_dev_version_is_not_an_update(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()->push($this->release('v1.0.0'))->push($this->release('v9.0.0'))]);
        $this->artisan('system:check-updates');
        $this->getJson('/api/v1/system/version')->assertJsonPath('data.update_available', false);

        config(['systemkeeper.version' => 'dev']);
        $this->artisan('system:check-updates');
        $this->getJson('/api/v1/system/version')->assertJsonPath('data.latest.version', 'v9.0.0');
        $this->getJson('/api/v1/system/version')->assertJsonPath('data.update_available', false);
    }

    public function test_github_error_is_reported_without_losing_release(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()->push($this->release(), 200)->push(null, 500)]);
        $this->artisan('system:check-updates');
        $this->artisan('system:check-updates');

        $this->getJson('/api/v1/system/version')
            ->assertJsonPath('data.latest.version', 'v1.1.0')
            ->assertJsonPath('data.check_error', 'GitHub ответил 500');
    }

    public function test_update_writes_request_for_updater(): void
    {
        $this->fakeRelease();
        $this->artisan('system:check-updates');
        touch($this->dir.'/heartbeat');

        $this->postJson('/api/v1/system/update')
            ->assertStatus(202)
            ->assertJsonPath('data.updater.status', 'pending')
            ->assertJsonPath('data.updater.to', 'v1.1.0');

        $this->assertSame(['version' => 'v1.1.0'], json_decode(File::get($this->dir.'/request.json'), true));

        $this->postJson('/api/v1/system/update')->assertStatus(422)->assertJsonPath('message', 'Обновление уже идёт.');
    }

    public function test_update_requires_running_updater_and_new_release(): void
    {
        $this->postJson('/api/v1/system/update')->assertStatus(422)->assertJsonPath('message', 'Обновлений нет.');

        $this->fakeRelease();
        $this->artisan('system:check-updates');
        touch($this->dir.'/heartbeat', time() - 600);

        $this->postJson('/api/v1/system/update')->assertStatus(422)->assertJsonValidationErrors('version');
        $this->assertFileDoesNotExist($this->dir.'/request.json');
    }

    public function test_updater_status_is_read_from_shared_dir(): void
    {
        File::put($this->dir.'/status.json', json_encode([
            'status' => 'rolled_back', 'message' => 'Версия v1.1.0 не запустилась', 'from' => 'v1.0.0', 'to' => 'v1.1.0', 'updated_at' => '2026-10-14T10:00:00Z',
        ]));

        $this->getJson('/api/v1/system/version')
            ->assertJsonPath('data.updater.status', 'rolled_back')
            ->assertJsonPath('data.updater.from', 'v1.0.0');
    }

    public function test_only_admin_can_update_or_check(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_admin' => false]));

        $this->postJson('/api/v1/system/update')->assertForbidden();
        $this->postJson('/api/v1/system/check')->assertForbidden();
        $this->getJson('/api/v1/system/version')->assertOk();
    }

    public function test_idle_command_waits_for_running_backups(): void
    {
        $this->artisan('system:idle')->assertSuccessful();

        $backup = Server::factory()->create()->backups()->create(['trigger' => 'manual', 'status' => 'running']);
        $this->artisan('system:idle')->assertFailed();

        // Зависший «running» от упавшего воркера обновлению не мешает
        Backup::whereKey($backup->id)->update(['updated_at' => now()->subHours(5)]);
        $this->artisan('system:idle')->assertSuccessful();
    }

    public function test_update_is_refused_while_backups_or_syncs_run(): void
    {
        $this->fakeRelease();
        $this->artisan('system:check-updates');
        touch($this->dir.'/heartbeat');

        $backup = Server::factory()->create()->backups()->create(['trigger' => 'manual', 'status' => 'running']);
        $this->getJson('/api/v1/system/version')->assertJsonPath('data.busy', ['backups' => 1, 'syncs' => 0]);
        $this->postJson('/api/v1/system/update')->assertStatus(422)->assertJsonPath('message', 'Идут бекапы — обновить можно будет после их окончания.');

        $backup->update(['status' => 'success']);
        $storage = BackupStorage::default();
        $storage->syncs()->create(['status' => 'running']);
        $this->postJson('/api/v1/system/update')->assertStatus(422);
        $this->artisan('system:idle')->assertFailed();

        $storage->syncs()->update(['status' => 'success']);
        $this->postJson('/api/v1/system/update')->assertStatus(202);
    }

    public function test_new_backups_do_not_start_during_update(): void
    {
        Queue::fake();
        touch($this->dir.'/heartbeat');
        File::put($this->dir.'/status.json', json_encode(['status' => 'restarting', 'to' => 'v1.1.0']));

        $server = Server::factory()->create();
        $server->backupSettings()->create(['enabled' => true, 'paths' => ['/var/www'], 'excludes' => [], 'schedule' => '* * * * *', 'pre_command_timeout' => 60]);

        $this->postJson("/api/v1/servers/{$server->id}/backups")->assertStatus(409)->assertJsonPath('message', 'Идёт обновление SystemKeeper — запустите бекап после него.');
        $this->artisan('backups:dispatch-due')->assertSuccessful();
        $this->assertSame(0, $server->backups()->count());

        // уже стоящий в очереди бекап откладывается, а не начинается
        $queued = $server->backups()->create(['trigger' => 'manual', 'status' => 'queued']);
        app()->call([new RunBackup($queued), 'handle']);
        $this->assertSame('queued', $queued->fresh()->status);
        Queue::assertPushed(RunBackup::class, 1);

        // обновлятор не запущен (нет heartbeat) — застрявший статус ничего не блокирует
        touch($this->dir.'/heartbeat', time() - 600);
        $this->postJson("/api/v1/servers/{$server->id}/backups")->assertStatus(409)->assertJsonPath('message', 'Бекап уже выполняется.');
    }

    public function test_changelog_merges_github_releases_with_local_file(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()->push($this->release())->push([[
            'tag_name' => 'v0.1.0', 'name' => '', 'body' => '**Full Changelog**', 'html_url' => 'https://github.com/x/y/releases/tag/v0.1.0', 'published_at' => '2026-10-14T12:00:00Z',
        ]])]);
        $this->artisan('system:check-updates');

        $data = $this->getJson('/api/v1/system/changelog')->assertOk()->json('data');
        $versions = array_column($data, 'version');

        // релизы с GitHub без черновиков и пре-релизов, плюс версии из CHANGELOG.md; новые сверху
        $this->assertSame('v1.1.0', $versions[0]);
        $this->assertContains('v0.9.0', $versions);
        $this->assertContains('v0.1.0', $versions);
        $this->assertNotContains('v99.0.0', $versions);
        $this->assertNotContains('v98.0.0-rc1', $versions);
        $this->assertTrue($data[0]['is_new']);
        $this->assertSame('- новое', $data[0]['notes']);
        $local = collect($data)->firstWhere('version', 'v0.1.0');
        $this->assertFalse($local['is_new']);
        $this->assertNull($local['url']);

        // версия есть и в CHANGELOG.md, и на GitHub — текст из файла, ссылка с GitHub
        $this->artisan('system:check-updates');
        $both = collect($this->getJson('/api/v1/system/changelog')->json('data'))->firstWhere('version', 'v0.1.0');
        $this->assertStringContainsString('Первый открытый релиз', $both['notes']);
        $this->assertSame('https://github.com/x/y/releases/tag/v0.1.0', $both['url']);
        $this->assertSame('2026-10-14', $local['published_at']);
        $this->assertStringContainsString('Первый открытый релиз', $local['notes']);
    }

    public function test_changelog_works_without_github(): void
    {
        config(['systemkeeper.version' => 'v0.1.0']);

        $data = $this->getJson('/api/v1/system/changelog')->assertOk()->json('data');

        $current = collect($data)->firstWhere('version', 'v0.1.0');
        $this->assertTrue($current['is_current']);
        $this->assertTrue($data[0]['is_new']);
    }
}
