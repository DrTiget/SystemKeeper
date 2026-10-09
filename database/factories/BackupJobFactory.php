<?php

namespace Database\Factories;

use App\Models\BackupJob;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupJob>
 */
class BackupJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'job-'.fake()->unique()->bothify('????##'),
            'server_id' => Server::factory(),
            'type' => BackupJob::TYPE_FILES,
            'paths' => ['/var/www', '/etc/nginx'],
            'excludes' => ['*.log'],
            'compression_level' => 3,
            'schedule' => null,
            'enabled' => true,
        ];
    }

    public function mysql(string $mode = BackupJob::DB_MODE_SSH): static
    {
        return $this->database(BackupJob::TYPE_MYSQL, $mode, 3306);
    }

    public function postgres(string $mode = BackupJob::DB_MODE_SSH): static
    {
        return $this->database(BackupJob::TYPE_POSTGRES, $mode, 5432);
    }

    private function database(string $type, string $mode, int $port): static
    {
        return $this->state([
            'type' => $type,
            'paths' => null,
            'excludes' => null,
            'db_mode' => $mode,
            'db_host' => $mode === BackupJob::DB_MODE_DIRECT ? 'db.example.com' : null,
            'db_port' => $port,
            'db_name' => 'app',
            'db_user' => 'dumper',
            'db_password' => 'db-secret-pass',
        ]);
    }
}
