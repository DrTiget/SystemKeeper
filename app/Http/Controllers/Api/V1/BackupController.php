<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupResource;
use App\Models\Backup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $backups = Backup::query()
            ->with('job:id,name,type,server_id')
            ->when($request->filled('backup_job_id'), fn ($q) => $q->where('backup_job_id', $request->integer('backup_job_id')))
            ->when($request->filled('server_id'), fn ($q) => $q->whereHas('job', fn ($j) => $j->where('server_id', $request->integer('server_id'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->paginate($perPage);

        return BackupResource::collection($backups);
    }

    public function show(Backup $backup): BackupResource
    {
        return new BackupResource($backup->load('job:id,name,type,server_id'));
    }

    public function download(Backup $backup): StreamedResponse|JsonResponse
    {
        if ($backup->status !== Backup::STATUS_SUCCESS || ! $backup->fileExists()) {
            return response()->json(['message' => 'Файл бекапа недоступен.'], 404);
        }

        return Storage::disk(config('backup.disk'))->download($backup->file_path, basename($backup->file_path), [
            'Content-Type' => 'application/zstd',
            'X-Checksum-Sha256' => $backup->sha256,
        ]);
    }

    public function destroy(Backup $backup): JsonResponse|Response
    {
        if ($backup->isActive()) {
            return response()->json(['message' => 'Бекап ещё выполняется — удалить его нельзя.'], 409);
        }

        $backup->delete();

        return response()->noContent();
    }
}
