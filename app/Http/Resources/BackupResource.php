<?php

namespace App\Http\Resources;

use App\Models\Backup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Backup
 */
class BackupResource extends JsonResource
{
    /**
     * Путь к файлу на диске наружу не отдаётся — файл скачивается через /backups/{id}/download.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'backup_job_id' => $this->backup_job_id,
            'backup_job' => $this->whenLoaded('job', fn () => [
                'id' => $this->job->id,
                'name' => $this->job->name,
                'type' => $this->job->type,
                'server_id' => $this->job->server_id,
            ]),
            'status' => $this->status,
            'trigger' => $this->trigger,
            'has_file' => $this->file_path !== null,
            'file_name' => $this->file_path ? basename($this->file_path) : null,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'md5' => $this->md5,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'duration_seconds' => $this->started_at && $this->finished_at ? $this->started_at->diffInSeconds($this->finished_at, true) : null,
            'error' => $this->error,
            'log' => $this->when($request->routeIs('backups.show'), $this->log),
            'restore_status' => $this->restore_status,
            'restore_checked_at' => $this->restore_checked_at,
            'restore_log' => $this->when($request->routeIs('backups.show'), $this->restore_log),
            'copies' => $this->whenLoaded('copies', fn () => $this->copies->map(fn ($copy) => [
                'id' => $copy->id,
                'storage_target_id' => $copy->storage_target_id,
                'storage_target' => $copy->relationLoaded('target') && $copy->target
                    ? ['id' => $copy->target->id, 'name' => $copy->target->name, 'type' => $copy->target->type]
                    : null,
                'remote_path' => $copy->remote_path,
                'status' => $copy->status,
                'size' => $copy->size,
                'md5' => $copy->md5,
                'sha256' => $copy->sha256,
                'error' => $copy->error,
                'attempts' => $copy->attempts,
                'uploaded_at' => $copy->uploaded_at,
            ])->values()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
