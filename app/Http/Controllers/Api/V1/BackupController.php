<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupResource;
use App\Models\Backup;
use App\Services\Backup\BackupPurgeException;
use App\Services\Backup\BackupPurger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $backups = Backup::query()
            ->with(['job:id,name,type,server_id', 'copies.target'])
            ->when($request->filled('backup_job_id'), fn ($q) => $q->where('backup_job_id', $request->integer('backup_job_id')))
            ->when($request->filled('server_id'), fn ($q) => $q->whereHas('job', fn ($j) => $j->where('server_id', $request->integer('server_id'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->paginate($perPage);

        return BackupResource::collection($backups);
    }

    public function show(Backup $backup): BackupResource
    {
        return new BackupResource($backup->load(['job:id,name,type,server_id', 'copies.target']));
    }

    /** Скачивание с Bearer-токеном. Браузеру удобнее временная ссылка — см. downloadLink(). */
    public function download(Backup $backup): BinaryFileResponse|JsonResponse
    {
        return $this->fileResponse($backup);
    }

    /**
     * Временная подписанная ссылка на файл бекапа: открывается без Authorization (в браузере — обычным переходом).
     * Подписан путь с query (без хоста), поэтому ссылка работает и за прокси, подменяющим Host.
     */
    public function downloadLink(Request $request, Backup $backup): JsonResponse
    {
        if (! $this->isDownloadable($backup)) {
            return response()->json(['message' => 'Файл бекапа недоступен.'], 404);
        }

        $expiresAt = now()->addMinutes((int) config('backup.download_link_minutes'));
        $relative = URL::temporarySignedRoute('backups.file', $expiresAt, ['backup' => $backup->id], absolute: false);

        return response()->json(['data' => [
            'url' => $request->getSchemeAndHttpHost().$relative,
            'expires_at' => $expiresAt->toIso8601String(),
        ]]);
    }

    /** Отдача файла по подписанной ссылке (маршрут за middleware `signed:relative`). */
    public function file(Backup $backup): BinaryFileResponse|JsonResponse
    {
        return $this->fileResponse($backup);
    }

    private function isDownloadable(Backup $backup): bool
    {
        return $backup->status === Backup::STATUS_SUCCESS && $backup->fileExists();
    }

    /**
     * Потоковая отдача с Content-Length, Accept-Ranges/Range (докачка), ETag и контрольной суммой в заголовке.
     */
    private function fileResponse(Backup $backup): BinaryFileResponse|JsonResponse
    {
        if (! $this->isDownloadable($backup)) {
            return response()->json(['message' => 'Файл бекапа недоступен.'], 404);
        }

        return response()->download(
            Storage::disk(config('backup.disk'))->path($backup->file_path),
            basename($backup->file_path),
            ['Content-Type' => 'application/zstd', 'X-Checksum-Sha256' => $backup->sha256, 'Cache-Control' => 'private, no-store'],
        );
    }

    /**
     * Удаляет бекап, его локальный файл и копии в хранилищах. `?keep_remote=1` — копии в хранилищах не трогать.
     */
    public function destroy(Request $request, Backup $backup, BackupPurger $purger): JsonResponse|Response
    {
        try {
            $purger->purge($backup, remote: ! $request->boolean('keep_remote'));
        } catch (BackupPurgeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->noContent();
    }
}
