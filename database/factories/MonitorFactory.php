<?php

namespace Database\Factories;

use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord(),
            'type' => Monitor::TYPE_HTTP,
            'target' => 'https://example.com',
            'interval_seconds' => 60,
            'timeout_seconds' => 10,
            'ssl_expiry_days' => 14,
            'heartbeat_grace_seconds' => 60,
            'failure_threshold' => 3,
            'enabled' => true,
            'enabled_at' => now(),
        ];
    }

    public function ofType(string $type, ?string $target = null): static
    {
        return $this->state(['type' => $type, 'target' => $target]);
    }

    public function heartbeat(): static
    {
        return $this->state(fn () => [
            'type' => Monitor::TYPE_HEARTBEAT,
            'target' => null,
            'heartbeat_token' => Str::random(40),
        ]);
    }

    public function paused(): static
    {
        return $this->state(['enabled' => false]);
    }
}
