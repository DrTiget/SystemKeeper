<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitor\StoreMonitorRequest;
use App\Http\Requests\Monitor\UpdateMonitorRequest;
use App\Http\Resources\IncidentResource;
use App\Http\Resources\MonitorCheckResource;
use App\Http\Resources\MonitorResource;
use App\Models\Monitor;
use App\Services\Monitoring\UptimeCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class MonitorController extends Controller
{
    public function index(Request $request, UptimeCalculator $uptime): AnonymousResourceCollection
    {
        $query = Monitor::query()->with('openIncident')->orderBy('name');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('status')) {
            $query->where('last_status', $request->string('status'));
        }
        if ($request->filled('enabled')) {
            $query->where('enabled', $request->boolean('enabled'));
        }

        $page = $query->paginate($this->perPage($request));
        $stats = $uptime->forMonitors($page->pluck('id')->all());
        $page->getCollection()->each(fn (Monitor $m) => $m->setAttribute('uptime', $stats[$m->id]));

        return MonitorResource::collection($page);
    }

    public function store(StoreMonitorRequest $request): JsonResponse
    {
        $monitor = new Monitor($request->validated());
        $monitor->type = $request->validated('type');
        $monitor->enabled ??= true;
        $monitor->enabled_at = now();
        if ($monitor->isHeartbeat()) {
            $monitor->heartbeat_token = Str::random(40);
        }
        $monitor->save();

        return (new MonitorResource($monitor->refresh()->load('openIncident')))->response()->setStatusCode(201);
    }

    public function show(Monitor $monitor, UptimeCalculator $uptime): MonitorResource
    {
        $monitor->load('openIncident')->setAttribute('uptime', $uptime->forMonitor($monitor));

        return new MonitorResource($monitor);
    }

    public function update(UpdateMonitorRequest $request, Monitor $monitor): MonitorResource
    {
        $monitor->fill($request->validated());
        if ($monitor->isDirty('enabled') && $monitor->enabled) {
            $this->markResumed($monitor);
        }
        $monitor->save();

        return new MonitorResource($monitor->load('openIncident'));
    }

    public function destroy(Monitor $monitor): Response
    {
        $monitor->delete();

        return response()->noContent();
    }

    public function pause(Monitor $monitor): MonitorResource
    {
        $monitor->update(['enabled' => false]);

        return new MonitorResource($monitor->load('openIncident'));
    }

    public function resume(Monitor $monitor): MonitorResource
    {
        $this->markResumed($monitor->fill(['enabled' => true]));
        $monitor->save();

        return new MonitorResource($monitor->load('openIncident'));
    }

    public function checks(Request $request, Monitor $monitor): AnonymousResourceCollection
    {
        $query = $monitor->checks()->orderByDesc('checked_at')->orderByDesc('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return MonitorCheckResource::collection($query->paginate($this->perPage($request)));
    }

    public function incidents(Request $request, Monitor $monitor): AnonymousResourceCollection
    {
        $query = $monitor->incidents()->orderByDesc('started_at')->orderByDesc('id');
        $this->filterIncidents($request, $query);

        return IncidentResource::collection($query->paginate($this->perPage($request)));
    }

    public function uptime(Monitor $monitor, UptimeCalculator $uptime): JsonResponse
    {
        return response()->json(['data' => $uptime->forMonitor($monitor)]);
    }

    /** Возобновление: сбрасываем счётчик неудач и отсчёт heartbeat, проверка — на ближайшем запуске планировщика. */
    private function markResumed(Monitor $monitor): void
    {
        $monitor->enabled_at = now();
        $monitor->next_check_at = null;
        $monitor->consecutive_failures = 0;
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), 100);
    }

    /** @param  Builder|Relation  $query */
    public static function filterIncidents(Request $request, $query): void
    {
        match ($request->string('status')->toString()) {
            'open' => $query->whereNull('resolved_at'),
            'resolved' => $query->whereNotNull('resolved_at'),
            default => null,
        };
    }
}
