<?php

namespace Tests\Feature;

use App\Exceptions\Storage\StorageException;
use App\Models\Server;
use App\Models\StorageTarget;
use App\Models\User;
use App\Services\Storage\Drivers\StorageDriver;
use App\Services\Storage\Drivers\StorageDriverFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StorageTypesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    private function s3Payload(array $override = []): array
    {
        return $override + [
            'name' => 'S3 основной', 'type' => 's3', 'endpoint' => 'https://storage.yandexcloud.net', 'region' => 'ru-central1',
            'bucket' => 'my-backups', 'prefix' => 'prod/db', 'access_key' => 'AKIA123', 'secret_key' => 'super-secret-key',
            'path_style' => false, 'storage_class' => 'COLD',
        ];
    }

    public function test_creates_s3_target_and_never_exposes_secret(): void
    {
        $response = $this->postJson('/api/v1/storage-targets', $this->s3Payload())
            ->assertCreated()
            ->assertJsonPath('data.type', 's3')
            ->assertJsonPath('data.bucket', 'my-backups')
            ->assertJsonPath('data.prefix', 'prod/db')
            ->assertJsonPath('data.endpoint', 'https://storage.yandexcloud.net')
            ->assertJsonPath('data.access_key', 'AKIA123')
            ->assertJsonPath('data.storage_class', 'COLD')
            ->assertJsonPath('data.has_secret', true)
            ->assertJsonPath('data.has_token', false)
            ->assertJsonMissingPath('data.secret')
            ->assertJsonMissingPath('data.secret_key');

        $this->assertStringNotContainsString('super-secret-key', $response->getContent());
        $target = StorageTarget::first();
        $this->assertSame('super-secret-key', $target->secret);
        $this->assertNotSame('super-secret-key', DB::table('storage_targets')->value('secret'));
        $this->assertSame('/prod/db', $target->base_path);

        $this->getJson('/api/v1/storage-targets')->assertOk()->assertJsonMissingPath('data.0.secret');
        $this->assertStringNotContainsString('super-secret-key', $this->getJson("/api/v1/storage-targets/{$target->id}")->getContent());
    }

    public function test_s3_validation_is_russian_and_per_type(): void
    {
        $response = $this->postJson('/api/v1/storage-targets', ['name' => 'x', 'type' => 's3'])->assertUnprocessable();

        $response->assertJsonValidationErrors(['region', 'bucket', 'access_key', 'secret_key']);
        $this->assertSame('Поле «бакет» обязательно для заполнения.', $response->json('errors.bucket.0'));
        $response->assertJsonMissingValidationErrors(['base_path', 'token', 'server_id']);

        $this->postJson('/api/v1/storage-targets', $this->s3Payload(['endpoint' => 'ftp://x', 'prefix' => 'a/../b', 'storage_class' => 'cold!']))
            ->assertJsonValidationErrors(['endpoint', 'prefix', 'storage_class']);
    }

    public function test_s3_defaults_for_compatible_storage(): void
    {
        $this->postJson('/api/v1/storage-targets', $this->s3Payload(['endpoint' => 'http://minio:9000', 'path_style' => true, 'prefix' => null, 'storage_class' => null]))
            ->assertCreated()
            ->assertJsonPath('data.prefix', '')
            ->assertJsonPath('data.path_style', true)
            ->assertJsonPath('data.storage_class', null);

        $this->assertSame('/', StorageTarget::first()->base_path);
    }

    public function test_s3_update_keeps_secret_unless_given_and_changes_settings(): void
    {
        $id = $this->postJson('/api/v1/storage-targets', $this->s3Payload())->json('data.id');

        $this->patchJson("/api/v1/storage-targets/{$id}", ['bucket' => 'other', 'prefix' => 'new', 'secret_key' => ''])
            ->assertOk()
            ->assertJsonPath('data.bucket', 'other')
            ->assertJsonPath('data.prefix', 'new')
            ->assertJsonPath('data.region', 'ru-central1')
            ->assertJsonPath('data.has_secret', true);
        $this->assertSame('super-secret-key', StorageTarget::find($id)->secret);

        $this->patchJson("/api/v1/storage-targets/{$id}", ['secret_key' => 'rotated'])->assertOk();
        $this->assertSame('rotated', StorageTarget::find($id)->secret);

        // тип не меняется, поля других типов игнорируются
        $this->patchJson("/api/v1/storage-targets/{$id}", ['type' => 'local', 'token' => 'x', 'server_id' => 999])->assertOk()->assertJsonPath('data.type', 's3');
        $this->assertNull(StorageTarget::find($id)->token);
    }

    public function test_creates_sftp_target_bound_to_server(): void
    {
        $server = Server::factory()->create();

        $this->postJson('/api/v1/storage-targets', ['name' => 'Резервный сервер', 'type' => 'sftp', 'server_id' => $server->id, 'base_path' => '/srv/backups'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'sftp')
            ->assertJsonPath('data.server_id', $server->id)
            ->assertJsonPath('data.server.host', $server->host)
            ->assertJsonPath('data.base_path', '/srv/backups')
            ->assertJsonPath('data.bucket', null)
            ->assertJsonPath('data.has_secret', false);

        $this->getJson('/api/v1/storage-targets')->assertOk()->assertJsonPath('data.0.server.id', $server->id);
    }

    public function test_sftp_validation(): void
    {
        $this->postJson('/api/v1/storage-targets', ['name' => 'x', 'type' => 'sftp'])
            ->assertJsonValidationErrors(['server_id', 'base_path']);
        $this->postJson('/api/v1/storage-targets', ['name' => 'x', 'type' => 'sftp', 'server_id' => 9999, 'base_path' => 'rel'])
            ->assertJsonValidationErrors(['server_id', 'base_path']);
    }

    public function test_server_used_as_storage_cannot_be_deleted(): void
    {
        $target = StorageTarget::factory()->sftp()->create();

        $this->deleteJson("/api/v1/servers/{$target->server_id}")->assertStatus(409);
        $this->assertDatabaseCount('servers', 1);

        $this->deleteJson("/api/v1/storage-targets/{$target->id}")->assertNoContent();
        $this->deleteJson("/api/v1/servers/{$target->server_id}")->assertNoContent();
    }

    public function test_check_uses_driver_and_returns_space(): void
    {
        $target = StorageTarget::factory()->s3()->create();
        $this->app->instance(StorageDriverFactory::class, new class extends StorageDriverFactory
        {
            public function make(StorageTarget $target): StorageDriver
            {
                return new class implements StorageDriver
                {
                    public function upload(string $localPath, string $remotePath): array
                    {
                        return [];
                    }

                    public function delete(string $remotePath): void {}

                    public function check(): array
                    {
                        throw new StorageException('Бакет не найден');
                    }
                };
            }
        });

        $this->postJson("/api/v1/storage-targets/{$target->id}/check")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Бакет не найден')
            ->assertJsonPath('data.free_space', null);
        $this->assertSame('failed', $target->fresh()->last_check_status);
    }
}
