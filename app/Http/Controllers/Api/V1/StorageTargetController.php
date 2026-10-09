<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorageTarget\StoreStorageTargetRequest;
use App\Http\Requests\StorageTarget\UpdateStorageTargetRequest;
use App\Http\Resources\StorageTargetResource;
use App\Models\StorageTarget;
use App\Services\Storage\Drivers\StorageDriverFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class StorageTargetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        return StorageTargetResource::collection(StorageTarget::query()->with('server')->orderBy('name')->paginate($perPage));
    }

    public function store(StoreStorageTargetRequest $request): JsonResponse
    {
        $data = $this->attributes($request->validated(), $request->validated('type'));
        $data += ['enabled' => true];

        return (new StorageTargetResource(StorageTarget::create($data)->load('server')))->response()->setStatusCode(201);
    }

    public function show(StorageTarget $storageTarget): StorageTargetResource
    {
        return new StorageTargetResource($storageTarget->load('server'));
    }

    public function update(UpdateStorageTargetRequest $request, StorageTarget $storageTarget): StorageTargetResource
    {
        $data = $this->attributes($request->validated(), $storageTarget->type, $storageTarget);
        // Пустой секрет не затирает сохранённый
        foreach (['token', 'secret'] as $secret) {
            if (array_key_exists($secret, $data) && ($data[$secret] === null || $data[$secret] === '')) {
                unset($data[$secret]);
            }
        }

        $storageTarget->update($data);

        return new StorageTargetResource($storageTarget->load('server'));
    }

    public function destroy(StorageTarget $storageTarget): Response
    {
        // Записи о копиях удаляются каскадом; сами файлы в хранилище не трогаются.
        $storageTarget->delete();

        return response()->noContent();
    }

    /** Проверка доступности: токен и место (ЯД), запись в бакет (S3), запись и место на сервере (sftp), каталог (local). */
    public function check(StorageTarget $storageTarget, StorageDriverFactory $drivers): JsonResponse
    {
        $error = null;
        $space = null;

        try {
            $space = $drivers->make($storageTarget)->check();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $storageTarget->forceFill([
            'last_checked_at' => now(),
            'last_check_status' => $error === null ? 'ok' : 'failed',
            'last_check_error' => $error === null ? null : Str::limit($error, 1000),
            'last_total_space' => $space['total_space'] ?? null,
            'last_free_space' => $space['free_space'] ?? null,
        ])->save();

        return response()->json(['data' => [
            'status' => $storageTarget->last_check_status,
            'error' => $storageTarget->last_check_error,
            'total_space' => $space['total_space'] ?? null,
            'used_space' => $space['used_space'] ?? null,
            'free_space' => $space['free_space'] ?? null,
        ]]);
    }

    /**
     * Переносит поля запроса в колонки модели: настройки s3 — в config, secret_key — в secret, prefix — в base_path.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input, string $type, ?StorageTarget $existing = null): array
    {
        $data = Arr::only($input, ['name', 'enabled', 'base_path', 'server_id', 'token']);
        if ($existing === null) {
            $data['type'] = $type;
        }

        if ($type === StorageTarget::TYPE_S3) {
            $config = $existing?->config ?? [];
            foreach (['endpoint', 'region', 'bucket', 'access_key', 'path_style', 'storage_class'] as $key) {
                if (array_key_exists($key, $input)) {
                    $config[$key] = $key === 'path_style' ? (bool) $input[$key] : ($input[$key] === '' ? null : $input[$key]);
                }
            }
            $data['config'] = $config + ['endpoint' => null, 'path_style' => false, 'storage_class' => null];
            if (array_key_exists('secret_key', $input)) {
                $data['secret'] = $input['secret_key'];
            }
            if ($existing === null || array_key_exists('prefix', $input)) {
                $data['base_path'] = '/'.trim((string) ($input['prefix'] ?? ''), '/');
            }
        }

        return $data;
    }
}
