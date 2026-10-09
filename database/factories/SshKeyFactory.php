<?php

namespace Database\Factories;

use App\Models\SshKey;
use App\Services\Ssh\SshKeyService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SshKey>
 */
class SshKeyFactory extends Factory
{
    public function definition(): array
    {
        $name = 'key-'.fake()->unique()->bothify('????##');
        $material = app(SshKeyService::class)->generate('ed25519', $name);

        return [
            'name' => $name,
            'private_key' => $material->privateKey,
            'passphrase' => null,
            'public_key' => $material->publicKey,
            'fingerprint' => $material->fingerprint,
            'type' => $material->type,
        ];
    }
}
