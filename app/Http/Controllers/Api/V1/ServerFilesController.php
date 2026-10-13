<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Ssh\SshConnectionException;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Ssh\RemoteFiles;
use App\Services\Ssh\RemoteFilesException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Обзор файлов сервера по SSH (для выбора каталогов бекапа).
 */
class ServerFilesController extends Controller
{
    /**
     * Содержимое каталога `path` (по умолчанию «/»).
     *
     * Ошибки: `422` — путь не абсолютный, не найден или нет доступа; `502` — не удалось подключиться по SSH.
     */
    public function index(Request $request, Server $server, RemoteFiles $files): JsonResponse
    {
        $request->validate(['path' => ['nullable', 'string', 'max:4096']]);

        try {
            $listing = $files->list($server, $request->string('path')->value() ?: '/');
        } catch (InvalidArgumentException|RemoteFilesException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['path' => [$e->getMessage()]]], 422);
        } catch (SshConnectionException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $listing]);
    }
}
