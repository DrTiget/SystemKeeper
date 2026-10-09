<?php

namespace App\Http\Resources;

use App\Models\SshKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SshKey
 */
class SshKeyResource extends JsonResource
{
    /**
     * Закрытый ключ и парольная фраза в ответ не попадают никогда.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'public_key' => $this->public_key,
            'fingerprint' => $this->fingerprint,
            'has_passphrase' => $this->passphrase !== null,
            'servers_count' => $this->whenCounted('servers'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
