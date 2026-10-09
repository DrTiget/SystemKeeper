<?php

namespace App\Models;

use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'host', 'port', 'username', 'auth_type', 'ssh_key_id', 'password', 'description',
    'last_checked_at', 'last_check_status', 'last_check_error', 'host_fingerprint',
])]
#[Hidden(['password'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    public const AUTH_KEY = 'key';

    public const AUTH_PASSWORD = 'password';

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'password' => 'encrypted',
            'last_checked_at' => 'datetime',
        ];
    }

    public function sshKey(): BelongsTo
    {
        return $this->belongsTo(SshKey::class);
    }
}
