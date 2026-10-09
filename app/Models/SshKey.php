<?php

namespace App\Models;

use Database\Factories\SshKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'private_key', 'passphrase', 'public_key', 'fingerprint', 'type'])]
#[Hidden(['private_key', 'passphrase'])]
class SshKey extends Model
{
    /** @use HasFactory<SshKeyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
        ];
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
