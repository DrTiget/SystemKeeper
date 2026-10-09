<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\StorageTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StorageTarget>
 */
class StorageTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'target-'.fake()->unique()->bothify('????##'),
            'type' => StorageTarget::TYPE_YANDEX_DISK,
            'token' => 'y0_'.fake()->sha1(),
            'base_path' => '/SystemKeeper',
            'enabled' => true,
        ];
    }

    public function local(string $path = '/tmp/systemkeeper-backups'): static
    {
        return $this->state(['type' => StorageTarget::TYPE_LOCAL, 'token' => null, 'base_path' => $path]);
    }

    public function s3(array $config = []): static
    {
        return $this->state([
            'type' => StorageTarget::TYPE_S3,
            'token' => null,
            'secret' => 'sk-secret-key',
            'base_path' => '/backups',
            'config' => $config + ['endpoint' => null, 'region' => 'us-east-1', 'bucket' => 'sk-bucket', 'access_key' => 'AKIATESTKEY', 'path_style' => false, 'storage_class' => null],
        ]);
    }

    public function sftp(?int $serverId = null, string $path = '/srv/backups'): static
    {
        return $this->state([
            'type' => StorageTarget::TYPE_SFTP,
            'token' => null,
            'server_id' => $serverId ?? Server::factory(),
            'base_path' => $path,
        ]);
    }
}
