<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Server\ServerRequest;
use App\Http\Resources\ServerResource;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Серверы и их SSH-подключение.
 */
class ServerController extends Controller
{
    /**
     * Список серверов по названию; `search` — по названию, хосту и описанию; `project_id` — серверы проекта (`none` — без проекта).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            // id проекта; `none` — серверы без проекта
            'project_id' => ['nullable', 'string', 'regex:/^(\d+|none)$/'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $servers = Server::query()
            ->with('project')
            ->when($request->input('project_id'), fn ($q, $p) => $p === 'none' ? $q->whereNull('project_id') : $q->where('project_id', (int) $p))
            ->when($request->string('search')->trim()->value(), fn ($q, $s) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('host', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%"),
            ))
            ->with('backupSettings')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return ServerResource::collection($servers);
    }

    public function store(ServerRequest $request): JsonResponse
    {
        $server = Server::create($request->serverData());

        return (new ServerResource($server))->response()->setStatusCode(201);
    }

    public function show(Server $server): ServerResource
    {
        return new ServerResource($server->load('backupSettings'));
    }

    /**
     * Частичное обновление. Смена хоста или порта сбрасывает запомненный отпечаток хоста.
     */
    public function update(ServerRequest $request, Server $server): ServerResource
    {
        $server->fill($request->serverData());
        if ($server->isDirty(['host', 'port'])) {
            $server->host_fingerprint = null;
        }
        $server->save();

        return new ServerResource($server);
    }

    public function destroy(Server $server): Response
    {
        abort_if(DatabaseConnection::where('server_id', $server->id)->exists(), 409, 'Через сервер подключены базы данных — сначала удалите или перенесите их.');

        $server->delete();

        return response()->noContent();
    }

    /**
     * Проверка SSH-подключения: выполняет `uname -a && uptime`, результат сохраняется в last_check.
     * Всегда 200 — итог в `data.status` (`ok` / `failed`).
     */
    public function check(Server $server, SshConnectorInterface $ssh): JsonResponse
    {
        $output = null;
        $error = null;

        try {
            $output = $ssh->exec($server, (string) config('ssh.check_command'));
            if ($output['exit_code'] !== 0) {
                $error = 'Команда завершилась с кодом '.($output['exit_code'] ?? '—').': '.trim($output['stderr']);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $server->refresh()->forceFill([
            'last_checked_at' => now(),
            'last_check_status' => $error === null ? 'ok' : 'failed',
            'last_check_error' => $error === null ? null : Str::limit($error, 1000),
        ])->save();

        return response()->json(['data' => [
            'status' => $server->last_check_status,
            'error' => $server->last_check_error,
            'output' => $output === null ? null : trim($output['stdout']),
            'server' => new ServerResource($server),
        ]]);
    }

    /**
     * Забыть отпечаток хоста (после переустановки сервера) — следующее подключение запомнит новый.
     */
    public function resetFingerprint(Server $server): ServerResource
    {
        $server->update(['host_fingerprint' => null]);

        return new ServerResource($server);
    }
}
