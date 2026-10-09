<?php

namespace App\Services\Ssh;

final readonly class KeyMaterial
{
    public function __construct(
        public string $privateKey,
        public ?string $passphrase,
        public string $publicKey,
        public string $fingerprint,
        public string $type,
    ) {}
}
