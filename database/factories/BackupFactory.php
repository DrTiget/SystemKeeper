<?php

namespace Database\Factories;

use App\Models\Backup;
use App\Models\BackupJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Backup>
 */
class BackupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'backup_job_id' => BackupJob::factory(),
            'status' => Backup::STATUS_PENDING,
            'trigger' => Backup::TRIGGER_MANUAL,
        ];
    }

    public function success(?string $path = null, string $content = 'zstd-data'): static
    {
        return $this->state(fn () => [
            'status' => Backup::STATUS_SUCCESS,
            'file_path' => $path,
            'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'md5' => md5($content),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function failed(string $error = 'boom'): static
    {
        return $this->state(['status' => Backup::STATUS_FAILED, 'error' => $error, 'started_at' => now()->subMinute(), 'finished_at' => now()]);
    }

    public function running(): static
    {
        return $this->state(['status' => Backup::STATUS_RUNNING, 'started_at' => now()]);
    }
}
