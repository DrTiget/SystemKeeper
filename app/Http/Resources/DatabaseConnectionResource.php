<?php

namespace App\Http\Resources;

use App\Models\DatabaseConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Подключение к БД без пароля (только признак has_password) и настройки бекапа.
 *
 * @mixin DatabaseConnection
 */
class DatabaseConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'engine' => $this->engine,
            'via' => $this->via,
            'server' => $this->server ? ['id' => $this->server->id, 'name' => $this->server->name, 'host' => $this->server->host] : null,
            'docker_container' => $this->docker_container,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'has_password' => $this->password !== null,
            'maintenance_database' => $this->maintenance_database,
            'backup' => [
                'enabled' => $this->backup_enabled,
                'schedule' => $this->schedule,
                'timezone' => config('backup.timezone'),
                'next_run_at' => $this->nextRunAt()?->toIso8601String(),
                'databases' => $this->databases,
                'exclude_tables' => $this->exclude_tables,
                'schema_only_tables' => $this->schema_only_tables,
                'options' => $this->resolvedOptions(),
            ],
            'last_check' => [
                'status' => $this->last_check_status,
                'error' => $this->last_check_error,
                'checked_at' => $this->last_checked_at,
                'server_version' => $this->server_version,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
