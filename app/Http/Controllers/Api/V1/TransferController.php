<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BackupStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Скачивание файла бекапа по временной подписанной ссылке — без входа: так облака (Яндекс Диск) сами забирают
 * бекап с SystemKeeper. Ссылку выдаёт SystemKeeper при копировании (TransferLinks), подпись закрывает хранилище и путь.
 */
class TransferController extends Controller
{
    /** @unauthenticated */
    public function show(BackupStorage $storage, string $path): StreamedResponse
    {
        // только файлы бекапов: servers|databases/<id>/<каталог бекапа>/<файл>
        abort_unless(preg_match('#^(servers|databases)/\d+/[^/]+/[^/]+$#', $path) && ! str_contains($path, '..'), 404);
        $fs = $storage->filesystem();
        abort_unless($fs->exists($path), 404);

        return $fs->download($path, basename($path), ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $fs->size($path)]);
    }
}
