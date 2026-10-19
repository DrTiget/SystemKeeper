<?php

namespace Database\Factories;

use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Monitor>
 */
class MonitorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainName(),
            'type' => Monitor::HTTP,
            'url' => 'https://example.com',
            'accepted_statuses' => ['200-299'],
            'headers' => [],
            'method' => 'GET',
            'follow_redirects' => true,
            'keyword_invert' => false,
            'ignore_tls' => false,
            'ssl_expiry_days' => 14,
            'dns_record_type' => 'A',
            'interval' => 60,
            'timeout' => 30,
            'retries' => 1,
            'retry_interval' => 60,
        ];
    }

    public function tcp(int $port = 22): static
    {
        return $this->state(['type' => Monitor::TCP, 'url' => null, 'hostname' => 'example.com', 'port' => $port]);
    }
}
