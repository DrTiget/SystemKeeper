<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\SshKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord(),
            'host' => fake()->ipv4(),
            'port' => 22,
            'username' => 'root',
            'auth_type' => Server::AUTH_KEY,
            'ssh_key_id' => SshKey::factory(),
            'password' => null,
            'description' => null,
        ];
    }

    public function withPassword(string $password = 'secret-pass'): static
    {
        return $this->state(['auth_type' => Server::AUTH_PASSWORD, 'ssh_key_id' => null, 'password' => $password]);
    }
}
