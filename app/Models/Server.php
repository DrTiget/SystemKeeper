<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Сервер с SSH-подключением: по ключу (private_key + необязательная passphrase) или по паролю.
 */
#[Fillable([
    'name', 'description', 'host', 'port', 'username', 'auth_type', 'private_key', 'passphrase', 'public_key', 'password',
    'host_fingerprint', 'last_checked_at', 'last_check_status', 'last_check_error',
])]
#[Hidden(['private_key', 'passphrase', 'password'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    public const AUTH_KEY = 'key';

    public const AUTH_PASSWORD = 'password';

    public const AUTH_TYPES = [self::AUTH_KEY, self::AUTH_PASSWORD];

    /** @var array<string, mixed> */
    protected $attributes = ['port' => 22];

    public function backupSettings(): HasOne
    {
        return $this->hasOne(ServerBackupSettings::class);
    }

    /** Сохранённые настройки бекапа или значения по умолчанию. */
    public function backupSettingsOrDefault(): ServerBackupSettings
    {
        return $this->backupSettings ?? ServerBackupSettings::defaultsFor($this);
    }

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
            'password' => 'encrypted',
            'last_checked_at' => 'datetime',
        ];
    }
}
