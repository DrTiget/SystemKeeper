<?php

namespace App\Services\Ssh;

use InvalidArgumentException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Exception\NoKeyLoadedException;
use Throwable;

class SshKeyService
{
    public function generate(string $type, string $comment = ''): KeyMaterial
    {
        $key = match ($type) {
            'rsa' => RSA::createKey(4096),
            'ed25519' => EC::createKey('Ed25519'),
            default => throw new InvalidArgumentException("Неподдерживаемый тип ключа: {$type}"),
        };

        return $this->describe($key, $key->toString('OpenSSH'), null, $comment);
    }

    /**
     * @throws InvalidArgumentException если ключ нельзя прочитать или тип не поддерживается
     */
    public function import(string $privateKey, ?string $passphrase = null, string $comment = ''): KeyMaterial
    {
        try {
            $key = PublicKeyLoader::load($privateKey, $passphrase ?: false);
        } catch (NoKeyLoadedException) {
            throw new InvalidArgumentException(
                $passphrase ? 'Не удалось прочитать закрытый ключ: неверная парольная фраза или формат.' : 'Не удалось прочитать закрытый ключ. Если он защищён парольной фразой, укажите её.',
            );
        } catch (Throwable) {
            throw new InvalidArgumentException('Не удалось прочитать закрытый ключ.');
        }

        if (! $key instanceof PrivateKey) {
            throw new InvalidArgumentException('Передан не закрытый ключ.');
        }

        return $this->describe($key, $privateKey, $passphrase ?: null, $comment);
    }

    private function describe(PrivateKey $key, string $privateKey, ?string $passphrase, string $comment): KeyMaterial
    {
        $type = match (true) {
            $key instanceof RSA\PrivateKey => 'rsa',
            $key instanceof EC\PrivateKey && $key->getCurve() === 'Ed25519' => 'ed25519',
            default => throw new InvalidArgumentException('Поддерживаются только ключи ed25519 и rsa.'),
        };

        $public = $key->getPublicKey();

        return new KeyMaterial(
            privateKey: $privateKey,
            passphrase: $passphrase,
            publicKey: $public->toString('OpenSSH', ['comment' => $comment]),
            fingerprint: 'SHA256:'.$public->getFingerprint('sha256'),
            type: $type,
        );
    }
}
