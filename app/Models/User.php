<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'settings'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Настройки пользователя по умолчанию; в БД хранятся только изменённые. */
    public const DEFAULT_SETTINGS = [
        'theme' => 'system',
    ];

    public const THEMES = ['light', 'dark', 'system'];

    /**
     * Настройки с подставленными значениями по умолчанию.
     *
     * @return array{theme: string}
     */
    public function resolvedSettings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, array_intersect_key($this->settings ?? [], self::DEFAULT_SETTINGS));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'settings' => 'array',
        ];
    }
}
