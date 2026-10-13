<?php

namespace App\Services\Ssh;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Throwable;

/**
 * Генерация и разбор SSH-ключей.
 */
class SshKeys
{
    public const TYPES = ['ed25519', 'rsa'];

    /**
     * Новая пара ключей без парольной фразы: закрытый ключ хранится зашифрованным в БД.
     *
     * @return array{private_key: string, public_key: string}
     */
    public static function generate(string $type, string $comment): array
    {
        $key = $type === 'rsa' ? RSA::createKey(4096) : EC::createKey('Ed25519');

        return [
            'private_key' => $key->toString('OpenSSH', ['comment' => $comment]),
            'public_key' => $key->getPublicKey()->toString('OpenSSH', ['comment' => $comment]),
        ];
    }

    /**
     * Открытый ключ в формате authorized_keys по закрытому; null — если ключ не читается.
     */
    public static function publicKeyOf(string $privateKey, ?string $passphrase, string $comment): ?string
    {
        try {
            $key = PublicKeyLoader::load($privateKey, $passphrase ?: false);
        } catch (Throwable) {
            return null;
        }

        return $key instanceof PrivateKey ? $key->getPublicKey()->toString('OpenSSH', ['comment' => $comment]) : null;
    }
}
