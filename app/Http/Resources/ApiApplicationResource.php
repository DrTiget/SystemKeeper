<?php

namespace App\Http\Resources;

use App\Models\ApiApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApiApplication
 */
class ApiApplicationResource extends JsonResource
{
    /** Открытый токен — только в ответе на создание и перевыпуск. */
    public ?string $plainToken = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'scopes' => $this->scopes ?? [],
            'has_token' => $this->token_hash !== null,
            /** Начало токена, чтобы узнать его среди других; сам токен не хранится. */
            'token_prefix' => $this->token_prefix,
            'token_created_at' => $this->token_created_at,
            'token' => $this->when($this->plainToken !== null, $this->plainToken),
            'last_used_at' => $this->last_used_at,
            'last_used_ip' => $this->last_used_ip,
            'created_by' => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null,
            'created_at' => $this->created_at,
        ];
    }

    public function withToken(string $token): static
    {
        $this->plainToken = $token;

        return $this;
    }
}
