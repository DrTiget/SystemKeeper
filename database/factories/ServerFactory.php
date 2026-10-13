<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord(),
            'host' => fake()->ipv4(),
            'port' => 22,
            'username' => 'root',
            'auth_type' => Server::AUTH_PASSWORD,
            'password' => 'secret',
        ];
    }
}
