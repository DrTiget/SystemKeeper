<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Storage\StorageException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorageTarget\StoreStorageTargetRequest;
use App\Http\Requests\StorageTarget\UpdateStorageTargetRequest;
use App\Http\Resources\StorageTargetResource;
use App\Models\StorageTarget;
use App\Services\Storage\YandexDiskClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

class StorageTargetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return StorageTargetResource::collection(StorageTarget::query()->orderBy('name')->paginate($perPage));
    }

    public function store(StoreStorageTargetRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ($data['type'] === StorageTarget::TYPE_LOCAL) {
            $data['token'] = null;
        }

        $data += ['enabled' => true];

        return (new StorageTargetResource(StorageTarget::create($data)))->response()->setStatusCode(201);
    }

    public function show(StorageTarget $storageTarget): StorageTargetResource
    {
        return new StorageTargetResource($storageTarget);
    }

    public function update(UpdateStorageTargetRequest $request, StorageTarget $storageTarget): StorageTargetResource
    {
        $data = $request->validated();
        // Пустой токен не затирает сохранённый; у локального хранилища токена нет.
        if (! $storageTarget->isYandexDisk() || ($data['token'] ?? '') === '') {
            unset($data['token']);
        }

        $storageTarget->update($data);

        return new StorageTargetResource($storageTarget);
    }

    public function destroy(StorageTarget $storageTarget): Response
    {
        // Записи о копиях удаляются каскадом; сами файлы в хранилище не трогаются.
        $storageTarget->delete();

        return response()->noContent();
    }

    /** Проверка доступности: для Яндекс.Диска — токен и свободное место, для локального — каталог. */
    public function check(StorageTarget $storageTarget): JsonResponse
    {
        $error = null;
        $space = null;

        try {
            $space = $storageTarget->isYandexDisk()
                ? (new YandexDiskClient((string) $storageTarget->token))->quota()
                : $this->checkLocal($storageTarget);
        } catch (StorageException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $storageTarget->forceFill([
            'last_checked_at' => now(),
            'last_check_status' => $error === null ? 'ok' : 'failed',
            'last_check_error' => $error === null ? null : Str::limit($error, 1000),
        ])->save();

        return response()->json(['data' => [
            'status' => $storageTarget->last_check_status,
            'error' => $storageTarget->last_check_error,
            'total_space' => $space['total_space'] ?? null,
            'used_space' => $space['used_space'] ?? null,
            'free_space' => $space['free_space'] ?? null,
        ]]);
    }

    /** @return array{total_space: int, used_space: int, free_space: int} */
    private function checkLocal(StorageTarget $target): array
    {
        $path = $target->base_path;
        if (! is_dir($path) && ! @mkdir($path, 0750, true) && ! is_dir($path)) {
            throw new StorageException("Каталог {$path} не существует и не может быть создан.");
        }
        if (! is_writable($path)) {
            throw new StorageException("Каталог {$path} недоступен для записи.");
        }

        $total = (int) disk_total_space($path);
        $free = (int) disk_free_space($path);

        return ['total_space' => $total, 'used_space' => $total - $free, 'free_space' => $free];
    }
}
