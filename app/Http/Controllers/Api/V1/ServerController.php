<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Ssh\HostFingerprintChangedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Server\StoreServerRequest;
use App\Http\Requests\Server\UpdateServerRequest;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use App\Services\Ssh\SshConnectorInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

class ServerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return ServerResource::collection(Server::query()->with('sshKey')->orderBy('name')->paginate($perPage));
    }

    public function store(StoreServerRequest $request): JsonResponse
    {
        $server = Server::create($this->attributes($request->validated()) + ['port' => 22]);

        return (new ServerResource($server->load('sshKey')))->response()->setStatusCode(201);
    }

    public function show(Server $server): ServerResource
    {
        return new ServerResource($server->load('sshKey'));
    }

    public function update(UpdateServerRequest $request, Server $server): ServerResource
    {
        $data = $this->attributes($request->validated());

        // Смена хоста или порта делает запомненный отпечаток недействительным
        if (($data['host'] ?? $server->host) !== $server->host || ($data['port'] ?? $server->port) !== $server->port) {
            $data['host_fingerprint'] = null;
        }

        $server->update($data);

        return new ServerResource($server->load('sshKey'));
    }

    public function destroy(Server $server): Response
    {
        $server->delete();

        return response()->noContent();
    }

    public function check(Server $server, SshConnectorInterface $ssh): JsonResponse
    {
        $output = null;
        $error = null;

        try {
            $output = $ssh->exec($server, (string) config('ssh.check_command'));
            if ($output['exit_code'] !== 0) {
                $error = 'Команда завершилась с кодом '.($output['exit_code'] ?? '—').': '.trim($output['stderr']);
            }
        } catch (HostFingerprintChangedException $e) {
            $error = $e->getMessage();
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
            'output' => $output,
            'checked_at' => $server->last_checked_at,
            'server' => new ServerResource($server->load('sshKey')),
        ]]);
    }

    public function resetFingerprint(Server $server): ServerResource
    {
        $server->update(['host_fingerprint' => null]);

        return new ServerResource($server->load('sshKey'));
    }

    /**
     * Приводит данные к согласованному виду: лишние учётные данные обнуляются.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        if (array_key_exists('port', $data)) {
            $data['port'] = $data['port'] ?? 22;
        }
        if (($data['auth_type'] ?? null) === Server::AUTH_KEY) {
            $data['password'] = null;
        } elseif (($data['auth_type'] ?? null) === Server::AUTH_PASSWORD) {
            $data['ssh_key_id'] = null;
        }

        return $data;
    }
}
