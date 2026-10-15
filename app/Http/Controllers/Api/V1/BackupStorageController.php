<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storage\BackupStorageRequest;
use App\Http\Resources\BackupStorageResource;
use App\Jobs\SyncStorage;
use App\Models\BackupCopy;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\ServerBackupSettings;
use App\Models\StorageSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Хранилища бекапов («Диски и облака»): встроенный диск SystemKeeper и бакеты S3.
 * Настройки хранения (сколько копий и лимит объёма) задаются у каждого сервера и базы данных.
 */
class BackupStorageController extends Controller
{
    /** Хранилища: свободное место (для диска) и занятый бекапами объём. */
    public function index(): AnonymousResourceCollection
    {
        return BackupStorageResource::collection(BackupStorage::query()->orderBy('id')->get());
    }

    /** Добавить S3-хранилище. Подключение не проверяется — для этого `POST /storages/{id}/check`. */
    public function store(BackupStorageRequest $request): JsonResponse
    {
        $storage = BackupStorage::create($request->storageData());

        return (new BackupStorageResource($storage))->response()->setStatusCode(201);
    }

    /** Хранилище и все серверы и базы данных, которые в него бекапятся (`sources`). */
    public function show(BackupStorage $storage): BackupStorageResource
    {
        return new BackupStorageResource($storage);
    }

    /** Изменить название и (у S3) параметры подключения; `secret` можно не передавать. */
    public function update(BackupStorageRequest $request, BackupStorage $storage): BackupStorageResource
    {
        $storage->update($request->storageData());

        return new BackupStorageResource($storage);
    }

    /**
     * Удалить хранилище. 409 — это встроенный диск, в нём есть бекапы или его выбрали серверы и базы данных.
     */
    public function destroy(BackupStorage $storage): Response
    {
        abort_if($storage->type === BackupStorage::LOCAL, 409, 'Диск SystemKeeper удалить нельзя.');
        abort_if($storage->copies()->where('status', BackupCopy::SUCCESS)->exists(), 409, 'В хранилище есть бекапы — сначала удалите их или дождитесь, пока их вытеснят новые.');
        $used = ServerBackupSettings::query()->whereJsonContains('storage_ids', $storage->id)->exists()
            || DatabaseConnection::query()->whereJsonContains('storage_ids', $storage->id)->exists();
        abort_if($used, 409, 'Хранилище выбрано у серверов или баз данных — сначала переключите их на другое.');

        $storage->copies()->delete(); // остались только неудачные копии — записи об ошибках
        $storage->delete();

        return response()->noContent();
    }

    /**
     * Синхронизация: сколько бекапов можно скопировать в это хранилище и последний запуск.
     *
     * `pending` — успешные бекапы, которых здесь нет, но они есть в других хранилищах; `last` — последняя синхронизация или null.
     */
    public function syncShow(BackupStorage $storage): JsonResponse
    {
        return response()->json(['data' => $this->syncState($storage)]);
    }

    /**
     * Скопировать сюда все бекапы из других хранилищ, которых здесь ещё нет. Выполняется в фоне, ход — в `GET /storages/{id}/sync`.
     *
     * `add_to_sources` — и новые бекапы этих серверов и баз сохранять сюда же.
     */
    public function sync(Request $request, BackupStorage $storage): JsonResponse
    {
        $data = $request->validate(['add_to_sources' => ['sometimes', 'boolean']]);

        if ($storage->syncs()->whereIn('status', [StorageSync::QUEUED, StorageSync::RUNNING])->exists()) {
            return response()->json(['message' => 'Синхронизация уже идёт.', 'errors' => ['storage' => ['Синхронизация уже идёт.']]], 422);
        }
        if (! $storage->missingBackups()->exists()) {
            return response()->json(['message' => 'Все бекапы уже есть в этом хранилище.', 'errors' => ['storage' => ['Все бекапы уже есть в этом хранилище.']]], 422);
        }

        $sync = $storage->syncs()->create([
            'user_id' => $request->user()->id,
            'status' => StorageSync::QUEUED,
            'add_to_sources' => (bool) ($data['add_to_sources'] ?? false),
        ]);
        SyncStorage::dispatch($sync);

        return response()->json(['data' => $this->syncState($storage)], 202);
    }

    private function syncState(BackupStorage $storage): array
    {
        $missing = $storage->missingBackups();
        $last = $storage->syncs()->latest('id')->first();

        return [
            'pending' => ['backups' => $missing->clone()->count(), 'size' => (int) $missing->clone()->sum('backups.size')],
            'last' => $last ? [
                'id' => $last->id,
                /** queued | running | success | failed */
                'status' => $last->status,
                'add_to_sources' => $last->add_to_sources,
                'total' => $last->total,
                'done' => $last->done,
                'failed' => $last->failed,
                'bytes_total' => $last->bytes_total,
                'bytes_done' => $last->bytes_done,
                'current' => $last->current,
                'error' => $last->error,
                'started_at' => $last->started_at,
                'finished_at' => $last->finished_at,
                'created_at' => $last->created_at,
            ] : null,
        ];
    }

    /**
     * Проверка: записать, прочитать и удалить небольшой файл. Всегда 200 — итог в `data.status` (`ok`/`failed`).
     */
    public function check(BackupStorage $storage): JsonResponse
    {
        $path = '.systemkeeper-check-'.Str::random(12);
        try {
            $fs = $storage->filesystem();
            $fs->put($path, 'ok');
            $ok = $fs->get($path) === 'ok';
            $fs->delete($path);
            $error = $ok ? null : 'Записанный файл читается с другим содержимым.';
        } catch (Throwable $e) {
            $error = self::explain($e);
        }

        return response()->json(['data' => ['status' => $error === null ? 'ok' : 'failed', 'error' => $error]]);
    }

    /** Короткое понятное сообщение вместо многострочной ошибки AWS SDK. */
    private static function explain(Throwable $e): string
    {
        $all = '';
        for ($x = $e; $x; $x = $x->getPrevious()) {
            $all .= ' '.$x->getMessage();
        }

        return match (true) {
            str_contains($all, 'NoSuchBucket') => 'Бакет не найден.',
            str_contains($all, 'InvalidAccessKeyId') || str_contains($all, 'SignatureDoesNotMatch') || str_contains($all, 'AccessDenied') || str_contains($all, '403') => 'Нет доступа: проверьте ключи и права на бакет.',
            str_contains($all, 'Could not resolve host') || str_contains($all, 'cURL error 6') => 'Адрес хранилища не найден.',
            str_contains($all, 'cURL error') || str_contains($all, 'Connection refused') => 'Не удалось подключиться к хранилищу.',
            default => Str::limit(trim(preg_replace('/\s+/', ' ', $e->getMessage())), 300),
        };
    }
}
