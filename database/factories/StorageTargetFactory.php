<?php

namespace Database\Factories;

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
}
