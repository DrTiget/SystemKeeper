<?php

namespace App\Http\Resources;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Server
 */
class ServerResource extends JsonResource
{
    /**
     * Пароль в ответ не попадает, только флаг has_password.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth_type' => $this->auth_type,
            'ssh_key_id' => $this->ssh_key_id,
            'ssh_key' => $this->whenLoaded('sshKey', fn () => $this->sshKey ? [
                'id' => $this->sshKey->id,
                'name' => $this->sshKey->name,
                'type' => $this->sshKey->type,
                'fingerprint' => $this->sshKey->fingerprint,
            ] : null),
            'has_password' => $this->password !== null,
            'description' => $this->description,
            'last_checked_at' => $this->last_checked_at,
            'last_check_status' => $this->last_check_status,
            'last_check_error' => $this->last_check_error,
            'host_fingerprint' => $this->host_fingerprint,
            'metrics_settings' => [
                'enabled' => $this->metrics_enabled,
                'interval_minutes' => $this->metrics_interval_minutes,
                'confirmations' => $this->threshold_confirmations,
                'thresholds' => [
                    'disk_percent' => $this->threshold_disk_percent,
                    'load_per_core' => $this->threshold_load_per_core,
                    'ram_percent' => $this->threshold_ram_percent,
                ],
            ],
            'metrics_collected_at' => $this->metrics_collected_at,
            'metrics_last_error' => $this->metrics_last_error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
