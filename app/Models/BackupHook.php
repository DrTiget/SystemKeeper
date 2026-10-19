<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Ссылка-хук бекапа: `POST /api/v1/hooks/{token}` запускает бекап источника без входа в систему.
 */
#[Fillable(['token', 'token_hash', 'last_used_at'])]
#[Hidden(['token', 'token_hash'])]
class BackupHook extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /** Новый секрет: старая ссылка перестаёт работать. */
    public function regenerate(): static
    {
        $token = 'skh_'.Str::random(40);

        return $this->fill(['token' => $token, 'token_hash' => hash('sha256', $token)]);
    }

    public function path(): string
    {
        return '/api/v1/hooks/'.$this->token;
    }
}
