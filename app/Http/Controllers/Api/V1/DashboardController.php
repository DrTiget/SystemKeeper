<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\StorageCopy;
use App\Models\StorageTarget;
use App\Services\Monitoring\UptimeCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Сводка для дашборда: фиксированное число запросов на блок, без N+1. */
class DashboardController extends Controller
{
    private const LIST_LIMIT = 5;

    public function __invoke(Request $request, UptimeCalculator $uptime): JsonResponse
    {
        $staleDays = min(max($request->integer('stale_days', 2), 1), 365);

        return response()->json(['data' => [
            'servers' => $this->servers(),
            'backups' => $this->backups($staleDays),
            'monitoring' => $this->monitoring($uptime),
            'storages' => $this->storages(),
            'generated_at' => now(),
        ]]);
    }

    private function servers(): array
    {
        $row = Server::query()->selectRaw(
            'count(*) as total, coalesce(sum(last_check_status = ?), 0) as ok, coalesce(sum(last_check_status = ?), 0) as failed',
            ['ok', 'failed'],
        )->first();

        return [
            'total' => (int) $row->total,
            'check_ok' => (int) $row->ok,
            'check_failed' => (int) $row->failed,
            'unchecked' => (int) $row->total - (int) $row->ok - (int) $row->failed,
        ];
    }

    private function backups(int $staleDays): array
    {
        $day = now()->subDay();
        $week = now()->subDays(7);
        $moment = 'coalesce(finished_at, created_at)';

        $counts = Backup::query()
            ->whereIn('status', [Backup::STATUS_SUCCESS, Backup::STATUS_FAILED])
            ->where(DB::raw($moment), '>=', $week)
            ->selectRaw(
                "coalesce(sum(status = ? and {$moment} >= ?), 0) as ok_24h, coalesce(sum(status = ? and {$moment} >= ?), 0) as failed_24h, ".
                'coalesce(sum(status = ?), 0) as ok_7d, coalesce(sum(status = ?), 0) as failed_7d',
                [Backup::STATUS_SUCCESS, $day, Backup::STATUS_FAILED, $day, Backup::STATUS_SUCCESS, Backup::STATUS_FAILED],
            )->first();

        $lastFailed = Backup::query()
            ->with('job:id,name')
            ->where('status', Backup::STATUS_FAILED)
            ->orderByRaw("{$moment} desc")
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'backup_job_id', 'error', 'finished_at', 'created_at'])
            ->map(fn (Backup $b) => [
                'id' => $b->id,
                'backup_job_id' => $b->backup_job_id,
                'job_name' => $b->job?->name,
                'error' => $b->error === null ? null : mb_substr($b->error, 0, 300),
                'finished_at' => $b->finished_at ?? $b->created_at,
            ])->all();

        $jobs = BackupJob::query()
            ->withMax(['backups as last_success_at' => fn ($q) => $q->where('status', Backup::STATUS_SUCCESS)], 'finished_at')
            ->get(['id', 'name', 'enabled', 'schedule', 'created_at']);

        $upcoming = $jobs->map(fn (BackupJob $j) => [$j, $j->nextRunAt()])
            ->filter(fn (array $p) => $p[1] !== null)
            ->sortBy(fn (array $p) => $p[1]->getTimestamp())
            ->take(self::LIST_LIMIT)
            ->map(fn (array $p) => ['backup_job_id' => $p[0]->id, 'job_name' => $p[0]->name, 'next_run_at' => $p[1]])
            ->values()->all();

        $threshold = now()->subDays($staleDays);
        $stale = $jobs->filter(fn (BackupJob $j) => $j->enabled
            && ($j->last_success_at === null ? $j->created_at->lt($threshold) : Carbon::parse($j->last_success_at)->lt($threshold)))
            ->map(fn (BackupJob $j) => [
                'backup_job_id' => $j->id,
                'job_name' => $j->name,
                'last_success_at' => $j->last_success_at === null ? null : Carbon::parse($j->last_success_at),
            ])->values()->all();

        $byStorage = StorageCopy::query()
            ->join('storage_targets', 'storage_targets.id', '=', 'storage_copies.storage_target_id')
            ->where('storage_copies.status', StorageCopy::STATUS_UPLOADED)
            ->groupBy('storage_targets.id', 'storage_targets.name', 'storage_targets.type')
            ->orderBy('storage_targets.name')
            ->selectRaw('storage_targets.id, storage_targets.name, storage_targets.type, count(*) as copies, coalesce(sum(storage_copies.size), 0) as size_bytes')
            ->get()
            ->map(fn ($r) => [
                'storage_target_id' => (int) $r->id, 'name' => $r->name, 'type' => $r->type,
                'copies' => (int) $r->copies, 'size_bytes' => (int) $r->size_bytes,
            ])->all();

        return [
            'success_24h' => (int) $counts->ok_24h,
            'failed_24h' => (int) $counts->failed_24h,
            'success_7d' => (int) $counts->ok_7d,
            'failed_7d' => (int) $counts->failed_7d,
            'last_failed' => $lastFailed,
            'upcoming' => $upcoming,
            'local_size_bytes' => (int) Backup::query()->where('status', Backup::STATUS_SUCCESS)->whereNotNull('file_path')->sum('size_bytes'),
            'size_by_storage' => $byStorage,
            'stale_days' => $staleDays,
            'stale_jobs' => $stale,
        ];
    }

    private function monitoring(UptimeCalculator $uptime): array
    {
        $row = Monitor::query()->selectRaw(
            'count(*) as total, coalesce(sum(enabled = 0), 0) as paused, '.
            'coalesce(sum(enabled = 1 and last_status = ?), 0) as up, coalesce(sum(enabled = 1 and last_status = ?), 0) as down',
            [Monitor::STATUS_UP, Monitor::STATUS_DOWN],
        )->first();

        $total = (int) $row->total;
        $paused = (int) $row->paused;
        $up = (int) $row->up;
        $down = (int) $row->down;

        $activeQuery = Incident::query()->whereNull('resolved_at');
        $incidents = (clone $activeQuery)
            ->with('monitor:id,name')
            ->orderByDesc('started_at')
            ->limit(10)
            ->get()
            ->map(fn (Incident $i) => [
                'id' => $i->id,
                'monitor_id' => $i->monitor_id,
                'monitor_name' => $i->monitor?->name,
                'reason' => $i->reason === null ? null : mb_substr($i->reason, 0, 300),
                'started_at' => $i->started_at,
            ])->all();

        $stats = $uptime->forMonitors(Monitor::query()->pluck('id')->all());
        $avg = fn (string $w) => ($vals = array_filter(array_column(array_column($stats, $w), 'percent'), fn ($p) => $p !== null)) === []
            ? null : round(array_sum($vals) / count($vals), 3);

        return [
            'total' => $total,
            'up' => $up,
            'down' => $down,
            'paused' => $paused,
            'pending' => $total - $up - $down - $paused,
            'active_incidents_count' => $activeQuery->count(),
            'active_incidents' => $incidents,
            'uptime_24h' => $avg('24h'),
            'uptime_30d' => $avg('30d'),
        ];
    }

    private function storages(): array
    {
        return StorageTarget::query()->orderBy('name')
            ->get(['id', 'name', 'type', 'enabled', 'last_checked_at', 'last_check_status', 'last_total_space', 'last_free_space'])
            ->map(fn (StorageTarget $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type,
                'enabled' => $t->enabled,
                'last_checked_at' => $t->last_checked_at,
                'last_check_status' => $t->last_check_status,
                'total_space' => $t->last_total_space,
                'free_space' => $t->last_free_space,
            ])->all();
    }
}
