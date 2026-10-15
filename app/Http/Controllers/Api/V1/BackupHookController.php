<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\BackupHook;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Services\Backup\BackupLauncher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Ссылки-хуки бекапа: секретный адрес, POST на который запускает бекап (например, из CI/CD перед деплоем).
 * Управление — у сервера и базы данных; сам хук работает без входа, по токену в адресе.
 */
class BackupHookController extends Controller
{
    /** Ссылка-хук сервера: `enabled`, `path` (адрес без домена), `last_used_at`. */
    public function serverShow(Server $server): JsonResponse
    {
        return $this->show($server);
    }

    /** Включить ссылку-хук сервера или выпустить новую (старая перестаёт работать). */
    public function serverStore(Server $server): JsonResponse
    {
        return $this->store($server);
    }

    /** Выключить ссылку-хук сервера. */
    public function serverDestroy(Server $server): Response
    {
        return $this->destroy($server);
    }

    /** Ссылка-хук базы данных. */
    public function databaseShow(DatabaseConnection $database): JsonResponse
    {
        return $this->show($database);
    }

    /** Включить ссылку-хук базы данных или выпустить новую. */
    public function databaseStore(DatabaseConnection $database): JsonResponse
    {
        return $this->store($database);
    }

    /** Выключить ссылку-хук базы данных. */
    public function databaseDestroy(DatabaseConnection $database): Response
    {
        return $this->destroy($database);
    }

    /**
     * Запустить бекап по ссылке-хуку (без входа). 202 — бекап в очереди; ход — по `status_url`.
     * 404 — ссылки нет или она выпущена заново; 409 — бекап уже идёт или обновляется система; 422 — не выбраны каталоги.
     */
    public function trigger(string $token, BackupLauncher $launcher): JsonResponse
    {
        $hook = $this->hook($token);
        $backup = $launcher->start($hook->source, Backup::TRIGGER_HOOK);
        $hook->forceFill(['last_used_at' => now()])->save();

        return response()->json(['data' => $this->status($hook, $backup)], 202);
    }

    /**
     * Ход бекапа, запущенного по ссылке (без входа): `status` — queued | running | success | failed.
     * Видны только бекапы источника этой ссылки.
     */
    public function backup(string $token, int $backup): JsonResponse
    {
        $hook = $this->hook($token);
        $model = Backup::query()->whereMorphedTo('source', $hook->source)->findOrFail($backup);

        return response()->json(['data' => $this->status($hook, $model)]);
    }

    private function hook(string $token): BackupHook
    {
        $hook = BackupHook::findByToken($token);
        abort_if($hook?->source === null, 404, 'Ссылка не найдена.');

        return $hook;
    }

    private function status(BackupHook $hook, Backup $backup): array
    {
        return [
            'id' => $backup->id,
            'status' => $backup->status,
            'started_at' => $backup->started_at,
            'finished_at' => $backup->finished_at,
            'size' => $backup->size,
            'error' => $backup->error,
            'status_url' => url($hook->path().'/backups/'.$backup->id),
        ];
    }

    private function show(Model $source): JsonResponse
    {
        return response()->json(['data' => $this->payload($source->backupHook)]);
    }

    private function store(Model $source): JsonResponse
    {
        $hook = $source->backupHook ?? $source->backupHook()->make();
        $hook->regenerate()->save();

        return response()->json(['data' => $this->payload($hook)], 201);
    }

    private function destroy(Model $source): Response
    {
        $source->backupHook()->delete();

        return response()->noContent();
    }

    private function payload(?BackupHook $hook): array
    {
        return [
            'enabled' => $hook !== null,
            /** Адрес без домена: `/api/v1/hooks/{token}` */
            'path' => $hook?->path(),
            'last_used_at' => $hook?->last_used_at,
            'created_at' => $hook?->created_at,
        ];
    }
}
