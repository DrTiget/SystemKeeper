<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\BackupJob\StoreBackupJobRequest;
use App\Http\Requests\BackupJob\UpdateBackupJobRequest;
use App\Http\Resources\BackupJobResource;
use App\Http\Resources\BackupResource;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BackupJobController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $jobs = BackupJob::query()
            ->with(['server:id,name', 'latestBackup'])
            ->withCount('backups')
            ->when($request->filled('server_id'), fn ($q) => $q->where('server_id', $request->integer('server_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('enabled'), fn ($q) => $q->where('enabled', $request->boolean('enabled')))
            ->orderBy('name')
            ->paginate($perPage);

        return BackupJobResource::collection($jobs);
    }

    public function store(StoreBackupJobRequest $request): JsonResponse
    {
        $job = BackupJob::create($this->attributes($request->validated(), create: true));

        return (new BackupJobResource($this->loaded($job)))->response()->setStatusCode(201);
    }

    public function show(BackupJob $backupJob): BackupJobResource
    {
        return new BackupJobResource($this->loaded($backupJob));
    }

    public function update(UpdateBackupJobRequest $request, BackupJob $backupJob): BackupJobResource
    {
        $backupJob->update($this->attributes($request->validated(), $backupJob->type));

        return new BackupJobResource($this->loaded($backupJob));
    }

    /**
     * Удаляет задание вместе с бекапами и их файлами.
     */
    public function destroy(BackupJob $backupJob): JsonResponse|Response
    {
        if ($backupJob->hasActiveBackup()) {
            return response()->json(['message' => 'У задания есть выполняющийся бекап — дождитесь его завершения.'], 409);
        }

        $backupJob->delete();

        return response()->noContent();
    }

    public function run(BackupJob $backupJob): JsonResponse
    {
        if ($backupJob->hasActiveBackup()) {
            return response()->json(['message' => 'Бекап этого задания уже выполняется или ожидает в очереди.'], 409);
        }

        $backup = $backupJob->backups()->create(['status' => Backup::STATUS_PENDING, 'trigger' => Backup::TRIGGER_MANUAL]);
        RunBackup::dispatch($backup);

        return (new BackupResource($backup->load('job')))->response()->setStatusCode(202);
    }

    private function loaded(BackupJob $job): BackupJob
    {
        return $job->load(['server:id,name', 'latestBackup'])->loadCount('backups');
    }

    /**
     * Приводит данные к согласованному виду: поля чужого типа обнуляются, режим БД по умолчанию — ssh.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?string $type = null, bool $create = false): array
    {
        $type ??= $data['type'] ?? null;

        if ($create) {
            $data += ['enabled' => true, 'db_ssl_verify' => true, 'compression_level' => (int) config('backup.compression_level')];
        }

        if ($type === BackupJob::TYPE_FILES) {
            return array_merge($data, [
                'db_mode' => null, 'db_host' => null, 'db_port' => null, 'db_name' => null, 'db_user' => null, 'db_password' => null,
            ]);
        }

        if ($create) {
            $data['db_mode'] ??= BackupJob::DB_MODE_SSH;
        }

        return array_merge($data, ['paths' => null, 'excludes' => null]);
    }
}
