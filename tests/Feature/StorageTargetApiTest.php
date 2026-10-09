<?php

namespace Tests\Feature;

use App\Models\StorageTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorageTargetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['yandex_disk.backoff_ms' => 0]);
    }

    private function login(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_requires_authentication(): void
    {
        $target = StorageTarget::factory()->create();

        $this->getJson('/api/v1/storage-targets')->assertUnauthorized();
        $this->postJson('/api/v1/storage-targets', [])->assertUnauthorized();
        $this->postJson("/api/v1/storage-targets/{$target->id}/check")->assertUnauthorized();
        $this->deleteJson("/api/v1/storage-targets/{$target->id}")->assertUnauthorized();
    }

    public function test_creates_target_and_never_exposes_token(): void
    {
        $this->login();

        $response = $this->postJson('/api/v1/storage-targets', [
            'name' => 'ЯД основной', 'type' => 'yandex_disk', 'token' => 'y0_secret', 'base_path' => '/Backups',
        ])->assertCreated()
            ->assertJsonPath('data.has_token', true)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonMissingPath('data.token');

        $this->assertStringNotContainsString('y0_secret', $response->getContent());
        $this->assertSame('y0_secret', StorageTarget::first()->token);
        $this->assertNotSame('y0_secret', \DB::table('storage_targets')->value('token'));

        $this->getJson('/api/v1/storage-targets')->assertOk()->assertJsonMissingPath('data.0.token');
    }

    public function test_yandex_requires_token_and_local_does_not(): void
    {
        $this->login();

        $this->postJson('/api/v1/storage-targets', ['name' => 'a', 'type' => 'yandex_disk', 'base_path' => '/x'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->postJson('/api/v1/storage-targets', ['name' => 'loc', 'type' => 'local', 'token' => 'ignored', 'base_path' => '/var/backups'])
            ->assertCreated()->assertJsonPath('data.has_token', false);
    }

    public function test_rejects_bad_type_and_path_traversal(): void
    {
        $this->login();

        $this->postJson('/api/v1/storage-targets', ['name' => 'a', 'type' => 'ftp', 'base_path' => '/x'])
            ->assertJsonValidationErrors('type');
        $this->postJson('/api/v1/storage-targets', ['name' => 'a', 'type' => 'local', 'base_path' => '/x/../etc'])
            ->assertJsonValidationErrors('base_path');
    }

    public function test_update_keeps_token_when_omitted_and_replaces_when_given(): void
    {
        $this->login();
        $target = StorageTarget::factory()->create(['token' => 'old']);

        $this->patchJson("/api/v1/storage-targets/{$target->id}", ['enabled' => false, 'name' => 'новое'])
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.name', 'новое');
        $this->assertSame('old', $target->fresh()->token);

        $this->patchJson("/api/v1/storage-targets/{$target->id}", ['token' => 'new'])->assertOk();
        $this->assertSame('new', $target->fresh()->token);
    }

    public function test_destroy(): void
    {
        $this->login();
        $target = StorageTarget::factory()->create();

        $this->deleteJson("/api/v1/storage-targets/{$target->id}")->assertNoContent();
        $this->assertDatabaseCount('storage_targets', 0);
    }

    public function test_check_reports_free_space(): void
    {
        $this->login();
        Http::fake(['cloud-api.yandex.net/v1/disk/' => Http::response(['total_space' => 1000, 'used_space' => 400])]);
        $target = StorageTarget::factory()->create(['token' => 'tok']);

        $this->postJson("/api/v1/storage-targets/{$target->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.free_space', 600);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'OAuth tok'));
        $this->assertSame('ok', $target->fresh()->last_check_status);
    }

    public function test_check_reports_invalid_token(): void
    {
        $this->login();
        Http::fake(['cloud-api.yandex.net/*' => Http::response(['description' => 'Unauthorized'], 401)]);
        $target = StorageTarget::factory()->create();

        $this->postJson("/api/v1/storage-targets/{$target->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.free_space', null);
        $this->assertStringContainsString('401', $target->fresh()->last_check_error);
    }

    public function test_check_local_directory(): void
    {
        $this->login();
        $dir = sys_get_temp_dir().'/sk-local-'.uniqid();
        $target = StorageTarget::factory()->local($dir)->create();

        $this->postJson("/api/v1/storage-targets/{$target->id}/check")
            ->assertOk()->assertJsonPath('data.status', 'ok');
        $this->assertDirectoryExists($dir);
        rmdir($dir);
    }
}
