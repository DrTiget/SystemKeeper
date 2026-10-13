<?php

namespace App\Http\Resources;

use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Сервер без секретов: вместо ключа, фразы и пароля — только признаки их наличия.
 *
 * @mixin Server
 */
class ServerResource extends JsonResource
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
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'auth_type' => $this->auth_type,
            'has_private_key' => $this->private_key !== null,
            'has_passphrase' => $this->passphrase !== null,
            'has_password' => $this->password !== null,
            'host_fingerprint' => $this->host_fingerprint,
            'last_check' => [
                'status' => $this->last_check_status,
                'error' => $this->last_check_error,
                'checked_at' => $this->last_checked_at,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
