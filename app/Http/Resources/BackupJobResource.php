<?php

namespace App\Http\Resources;

use App\Models\BackupJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BackupJob
 */
class BackupJobResource extends JsonResource
{
    /**
     * Пароль БД в ответ не попадает, только флаг has_db_password.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'server_id' => $this->server_id,
            'server' => $this->whenLoaded('server', fn () => ['id' => $this->server->id, 'name' => $this->server->name]),
            'type' => $this->type,
            'paths' => $this->paths,
            'excludes' => $this->excludes,
            'db_mode' => $this->db_mode,
            'db_host' => $this->db_host,
            'db_port' => $this->db_port,
            'db_name' => $this->db_name,
            'db_user' => $this->db_user,
            'has_db_password' => $this->db_password !== null,
            'db_ssl_verify' => $this->db_ssl_verify,
            'verify_restore' => $this->verify_restore,
            'verify_every_n' => $this->verify_every_n,
            'verify_every_days' => $this->verify_every_days,
            'verify_query' => $this->verify_query,
            'verify_query_min' => $this->verify_query_min,
            'restore_host' => $this->restore_host,
            'restore_port' => $this->restore_port,
            'restore_user' => $this->restore_user,
            'has_restore_password' => $this->restore_password !== null,
            'compression_level' => $this->compression_level,
            'schedule' => $this->schedule,
            'enabled' => $this->enabled,
            'next_run_at' => $this->nextRunAt(),
            'last_scheduled_at' => $this->last_scheduled_at,
            'retention_count' => $this->retention_count,
            'retention_days' => $this->retention_days,
            'keep_daily' => $this->keep_daily,
            'keep_weekly' => $this->keep_weekly,
            'keep_monthly' => $this->keep_monthly,
            'storage_target_ids' => $this->storage_target_ids,
            'backups_count' => $this->whenCounted('backups'),
            'latest_backup' => $this->whenLoaded('latestBackup', fn () => $this->latestBackup ? new BackupResource($this->latestBackup) : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
