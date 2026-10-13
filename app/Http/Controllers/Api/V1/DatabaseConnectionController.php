<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Ssh\SshConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Database\DatabaseConnectionRequest;
use App\Http\Resources\DatabaseConnectionResource;
use App\Models\DatabaseConnection;
use App\Services\Database\BackupUserProvisioner;
use App\Services\Database\DatabaseClient;
use App\Services\Database\DatabaseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Подключения к базам данных и настройки их бекапа.
 */
class DatabaseConnectionController extends Controller
{
    /**
     * Список по названию; `search` — по названию, хосту и описанию; `server_id` — подключения через этот сервер.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'server_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = DatabaseConnection::query()
            ->with('server')
            ->when($request->integer('server_id'), fn ($q, $id) => $q->where('server_id', $id))
            ->when($request->string('search')->trim()->value(), fn ($q, $s) => $q->where(
                fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('host', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%"),
            ))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return DatabaseConnectionResource::collection($items);
    }

    public function store(DatabaseConnectionRequest $request): JsonResponse
    {
        $connection = DatabaseConnection::create($request->connectionData());

        return (new DatabaseConnectionResource($connection->load('server')))->response()->setStatusCode(201);
    }

    public function show(DatabaseConnection $database): DatabaseConnectionResource
    {
        return new DatabaseConnectionResource($database->load('server'));
    }

    /**
     * Частичное обновление: подключение и/или настройки бекапа.
     */
    public function update(DatabaseConnectionRequest $request, DatabaseConnection $database): DatabaseConnectionResource
    {
        $database->update($request->connectionData());

        return new DatabaseConnectionResource($database->load('server'));
    }

    public function destroy(DatabaseConnection $database): Response
    {
        $database->delete();

        return response()->noContent();
    }

    /**
     * Проверка подключения: версия СУБД и список баз. Всегда 200 — итог в `data.status`.
     */
    public function check(DatabaseConnection $database, DatabaseClient $client): JsonResponse
    {
        $databases = null;
        $error = null;
        $version = null;

        try {
            $version = $client->version($database);
            $databases = $client->databases($database);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $database->forceFill([
            'last_checked_at' => now(),
            'last_check_status' => $error === null ? 'ok' : 'failed',
            'last_check_error' => $error === null ? null : Str::limit($error, 1000),
            'server_version' => $version ?? $database->server_version,
        ])->save();

        return response()->json(['data' => [
            'status' => $database->last_check_status,
            'error' => $database->last_check_error,
            'version' => $version,
            'databases' => $databases,
            'connection' => new DatabaseConnectionResource($database->load('server')),
        ]]);
    }

    /**
     * Базы данных на сервере СУБД: `{name, size, system}`.
     * Ошибки: `422` — ошибка СУБД (доступ, клиент не найден), `502` — нет SSH.
     */
    public function schemas(DatabaseConnection $database, DatabaseClient $client): JsonResponse
    {
        return $this->guard(fn () => $client->databases($database));
    }

    /**
     * Таблицы базы: `{name, rows, size}`; для PostgreSQL имя — «схема.таблица».
     */
    public function tables(Request $request, DatabaseConnection $database, DatabaseClient $client): JsonResponse
    {
        $request->validate(['database' => ['required', 'string', 'max:255']]);

        return $this->guard(fn () => $client->tables($database, $request->string('database')->value()));
    }

    /**
     * Создать в СУБД пользователя для бекапов (только чтение) и сохранить его в подключении. Только для via=ssh.
     *
     * Тело: `{username?: "systemkeeper_backup", admin: {mode: "local"|"credentials", username?, password?}}`.
     * Учётные данные администратора не сохраняются. Ошибки: `422` — СУБД/права, `502` — нет SSH.
     */
    public function provisionUser(Request $request, DatabaseConnection $database, BackupUserProvisioner $provisioner): JsonResponse
    {
        abort_if($database->via !== DatabaseConnection::VIA_SSH || ! $database->server, 422, 'Автонастройка пользователя доступна только при подключении через сервер.');

        $data = $request->validate([
            'username' => ['nullable', 'string', 'regex:/^[a-z_][a-z0-9_]{0,31}$/'],
            'admin.mode' => ['required', 'in:local,credentials'],
            'admin.username' => ['required_if:admin.mode,credentials', 'nullable', 'string', 'max:255'],
            'admin.password' => ['nullable', 'string', 'max:1000'],
        ], [
            'username.regex' => 'Имя пользователя: латинские строчные буквы, цифры и _, до 32 символов.',
        ], ['admin.username' => 'логин администратора']);

        try {
            $log = $provisioner->provision($database, $data['username'] ?? 'systemkeeper_backup', $data['admin']);
        } catch (DatabaseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SshConnectionException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => [
            'log' => $log,
            'connection' => new DatabaseConnectionResource($database->refresh()->load('server')),
        ]]);
    }

    private function guard(callable $fn): JsonResponse
    {
        try {
            return response()->json(['data' => $fn()]);
        } catch (DatabaseException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (SshConnectionException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
