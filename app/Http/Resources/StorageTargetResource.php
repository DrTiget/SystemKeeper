<?php

namespace App\Http\Resources;

use App\Models\StorageTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StorageTarget
 */
class StorageTargetResource extends JsonResource
{
    /**
     * Токен и secret key в ответ не попадают никогда — только флаги has_token / has_secret.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'base_path' => $this->base_path,
            'server_id' => $this->server_id,
            'server' => $this->when($this->type === StorageTarget::TYPE_SFTP, fn () => $this->server?->only(['id', 'name', 'host', 'port'])),
            'endpoint' => $this->s3($this->setting('endpoint')),
            'region' => $this->s3($this->setting('region')),
            'bucket' => $this->s3($this->setting('bucket')),
            'prefix' => $this->s3(trim($this->base_path, '/')),
            'access_key' => $this->s3($this->setting('access_key')),
            'path_style' => $this->s3((bool) $this->setting('path_style', false)),
            'storage_class' => $this->s3($this->setting('storage_class')),
            'enabled' => $this->enabled,
            'has_token' => $this->token !== null,
            'has_secret' => $this->secret !== null,
            'last_checked_at' => $this->last_checked_at,
            'last_check_status' => $this->last_check_status,
            'last_check_error' => $this->last_check_error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /** Поля s3 имеют смысл только для этого типа, у остальных — null. */
    private function s3(mixed $value): mixed
    {
        return $this->type === StorageTarget::TYPE_S3 ? $value : null;
    }
}
