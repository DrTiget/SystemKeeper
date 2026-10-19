<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupResource;
use App\Models\Backup;
use App\Models\BackupCopy;
use App\Models\DatabaseConnection;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\Server;
use App\Models\User;
use App\Services\Backup\BackupLauncher;
use App\Services\Backup\BackupManifest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Запуски бекапов серверов и баз данных: история, ручной запуск, скачивание файлов.
 */
class BackupController extends Controller
{
    /**
     * Бекапы всех серверов и баз данных проекта, новые сверху. В каждом — `source` (`type`, `id`, `name`).
     */
    public function projectIndex(Request $request, Project $project): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        $backups = Backup::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('source_type', 'server')->whereIn('source_id', $project->servers()->select('id')))
                ->orWhere(fn ($q) => $q->where('source_type', 'database')->whereIn('source_id', $project->databases()->select('id'))))
            ->with(['user', 'source.project', 'copies.storage'])
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return BackupResource::collection($backups);
    }

    /**
     * Сводка: последний бекап каждого сервера и базы данных (`latest` — null, если бекапов нет) и время последнего
     * удачного (`last_success_at`), объём и число всех удачных бекапов (`total_size`, `total_count`). Плюс группы и проекты — чтобы собрать дерево «группа → проект → сервер → базы».
     */
    public function latest(): JsonResponse
    {
        $latestIds = Backup::query()->selectRaw('max(id)')->groupBy('source_type', 'source_id');
        $latest = Backup::query()->whereIn('id', $latestIds)->get()->keyBy(fn (Backup $b) => $b->source_type.':'.$b->source_id);
        $success = Backup::query()->where('status', Backup::SUCCESS)
            ->selectRaw('source_type, source_id, max(finished_at) as at')->groupBy('source_type', 'source_id')
            ->get()->mapWithKeys(fn ($r) => [$r->source_type.':'.$r->source_id => $r->at]);
        $totals = Backup::query()->where('status', Backup::SUCCESS)
            ->selectRaw('source_type, source_id, sum(size) as size, count(*) as count')->groupBy('source_type', 'source_id')
            ->get()->keyBy(fn ($r) => $r->source_type.':'.$r->source_id);

        $backup = fn (string $key) => [
            'latest' => ($b = $latest->get($key)) ? [
                'id' => $b->id, 'status' => $b->status, 'trigger' => $b->trigger, 'size' => $b->size, 'error' => $b->error,
                'started_at' => $b->started_at, 'finished_at' => $b->finished_at, 'created_at' => $b->created_at,
            ] : null,
            'last_success_at' => isset($success[$key]) ? Carbon::parse($success[$key]) : null,
            /** Все хранящиеся удачные бекапы источника: объём и число */
            'total_size' => (int) ($totals[$key]->size ?? 0),
            'total_count' => (int) ($totals[$key]->count ?? 0),
        ];

        return response()->json(['data' => [
            'groups' => ProjectGroup::query()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::query()->orderBy('name')->get(['id', 'name', 'group_id']),
            'servers' => Server::query()->with('backupSettings')->orderBy('name')->get()->map(fn (Server $s) => [
                'id' => $s->id, 'name' => $s->name, 'host' => $s->host, 'project_id' => $s->project_id,
                'schedule_enabled' => (bool) $s->backupSettings?->enabled,
                ...$backup('server:'.$s->id),
            ]),
            'databases' => DatabaseConnection::query()->orderBy('name')->get()->map(fn (DatabaseConnection $d) => [
                'id' => $d->id, 'name' => $d->name, 'engine' => $d->engine, 'server_id' => $d->server_id, 'project_id' => $d->project_id,
                'schedule_enabled' => (bool) $d->backup_enabled,
                ...$backup('database:'.$d->id),
            ]),
        ]]);
    }

    /** История бекапов сервера, новые сверху. */
    public function serverIndex(Request $request, Server $server): AnonymousResourceCollection
    {
        return $this->history($request, $server);
    }

    /**
     * Запустить бекап сервера сейчас. 202 — бекап поставлен в очередь, ход — в `GET /backups/{id}`.
     * 409 — бекап уже идёт; 422 — не выбраны каталоги.
     */
    public function serverRun(Request $request, Server $server): JsonResponse
    {
        return $this->start($request, $server);
    }

    /** История бекапов базы данных, новые сверху. */
    public function databaseIndex(Request $request, DatabaseConnection $database): AnonymousResourceCollection
    {
        return $this->history($request, $database);
    }

    /**
     * Запустить бекап базы данных сейчас. 202 — поставлен в очередь; 409 — бекап уже идёт.
     */
    public function databaseRun(Request $request, DatabaseConnection $database): JsonResponse
    {
        return $this->start($request, $database);
    }

    /** Бекап с подробностями (`meta`), копиями по местам хранения и источником (`source` с проектом). */
    public function show(Backup $backup): BackupResource
    {
        return new BackupResource($backup->load(['user', 'copies.storage', 'source.project']));
    }

    /** Удаляет запись и файлы бекапа. 409 — бекап ещё выполняется. */
    public function destroy(Backup $backup, BackupManifest $manifest): Response
    {
        abort_if($backup->isActive(), 409, 'Бекап ещё выполняется.');
        $source = $backup->source;
        $storages = $backup->copies()->with('storage')->get()->pluck('storage')->filter();
        $backup->delete();

        // убираем бекап и из оглавления мест, где он лежал
        if ($source) {
            $storages->each(fn ($storage) => $manifest->sync($storage, $source));
        }

        return response()->noContent();
    }

    /**
     * Скачать файл бекапа (имя — из `files[].name`). Берётся удачная копия — с диска SystemKeeper, если она там есть,
     * иначе из облака потоком через SystemKeeper; `storage_id` — из конкретного хранилища.
     */
    public function download(Request $request, Backup $backup, string $file): StreamedResponse
    {
        abort_unless($backup->path && in_array($file, array_column($backup->files ?? [], 'name'), true), 404, 'Файл не найден.');

        $storage = $request->filled('storage_id')
            ? $backup->copies->where('status', BackupCopy::SUCCESS)->firstWhere('storage_id', $request->integer('storage_id'))?->storage
            : $backup->readableStorage();
        abort_if($storage === null, 404, 'Копии бекапа нет.');
        $fs = $storage->filesystem();
        $path = $backup->path.'/'.$file;
        try {
            abort_unless($fs->exists($path), 404, 'Файл не найден.');
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            abort(502, 'Хранилище недоступно: '.$e->getMessage());
        }

        return $fs->download($path, $file);
    }

    private function history(Request $request, Model $source): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return BackupResource::collection(
            Backup::query()->whereMorphedTo('source', $source)->with(['user', 'copies.storage'])->latest('id')->paginate($request->integer('per_page', 20)),
        );
    }

    private function start(Request $request, Model $source): JsonResponse
    {
        $backup = app(BackupLauncher::class)->start($source, Backup::TRIGGER_MANUAL, $request->user() instanceof User ? $request->user() : null);

        return (new BackupResource($backup->load(['user', 'copies.storage'])))->response()->setStatusCode(202);
    }
}
