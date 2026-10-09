<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

#[Signature('alerts:vapid-keys {--force : Перезаписать существующие ключи (все подписки браузеров станут недействительны)}')]
#[Description('Генерирует VAPID-ключи для Web Push и записывает их в .env')]
class GenerateVapidKeys extends Command
{
    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();
        $env = is_file($path) ? (string) file_get_contents($path) : '';

        if (! $this->option('force') && preg_match('/^VAPID_PRIVATE_KEY=.+$/m', $env)) {
            $this->error('VAPID-ключи уже заданы. Для замены используйте --force.');

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();
        foreach (['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']] as $name => $value) {
            $env = preg_match("/^{$name}=.*$/m", $env)
                ? preg_replace("/^{$name}=.*$/m", "{$name}={$value}", $env)
                : rtrim($env)."\n{$name}={$value}\n";
        }
        file_put_contents($path, $env);

        $this->info('VAPID-ключи записаны в .env. Перезапустите app/queue, чтобы они подхватились.');

        return self::SUCCESS;
    }
}
