<?php

namespace Database\Factories;

use App\Models\DatabaseConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseConnection>
 */
class DatabaseConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord(),
            'engine' => DatabaseConnection::MYSQL,
            'via' => DatabaseConnection::VIA_DIRECT,
            'host' => '127.0.0.1',
            'port' => 3306,
            'username' => 'root',
            'password' => 'secret',
            'schedule' => '0 3 * * *',
        ];
    }
}
