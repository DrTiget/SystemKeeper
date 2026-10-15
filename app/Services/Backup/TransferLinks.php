<?php

namespace App\Services\Backup;

use App\Models\BackupStorage;
use Illuminate\Support\Facades\URL;

/**
 * Временные подписанные ссылки на файл бекапа: по ним облако (Яндекс Диск) само скачивает файл с SystemKeeper.
 * Ссылка открывает ровно один файл одного хранилища и живёт `systemkeeper.transfer_link_ttl` минут.
 */
class TransferLinks
{
    /** Публичный адрес SystemKeeper; null — его нет (localhost, внутренняя сеть), ссылки бесполезны. */
    public function base(): ?string
    {
        $url = rtrim((string) (config('systemkeeper.public_url') ?: config('app.url')), '/');
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || ! str_contains($host, '.')) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        return $url;
    }

    public function for(BackupStorage $storage, string $path): ?string
    {
        $base = $this->base();
        if ($base === null) {
            return null;
        }

        return $base.URL::temporarySignedRoute(
            'transfers.show',
            now()->addMinutes((int) config('systemkeeper.transfer_link_ttl')),
            ['storage' => $storage->id, 'path' => $path],
            absolute: false,
        );
    }
}
