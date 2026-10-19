<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitor\MonitorRequest;
use App\Http\Resources\MonitorIncidentResource;
use App\Http\Resources\MonitorResource;
use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use App\Services\Monitoring\CheckResult;
use App\Services\Monitoring\MonitorChecker;
use App\Services\Monitoring\MonitorRecorder;
use App\Services\Monitoring\MonitorStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Мониторинг доступности: мониторы http / tcp / ping / dns, их проверки и сбои.
 */
class MonitorController extends Controller
{
    /** Период графика → [за сколько, размер интервала усреднения в секундах (0 — без усреднения)] */
    private const PERIODS = ['24h' => ['-24 hours', 0], '7d' => ['-7 days', 3600], '30d' => ['-30 days', 10800]];

    public function __construct(private MonitorStats $stats) {}

    /** Мониторы по названию; `project_id` (`none` — без проекта), `status` (`up`, `down`, `pending`, `paused`). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'project_id' => ['nullable', 'string', 'regex:/^(\d+|none)$/'],
            'status' => ['nullable', 'in:up,down,pending,paused'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Monitor::query()->with('project')
            ->when($request->input('project_id'), fn ($q, $p) => $p === 'none' ? $q->whereNull('project_id') : $q->where('project_id', (int) $p))
            ->when($request->input('status'), fn ($q, $s) => $s === Monitor::PAUSED
                ? $q->where('enabled', false)
                : $q->where('enabled', true)->where('status', $s))
            ->orderBy('name')->orderBy('id')
            ->paginate($request->integer('per_page', 25));
        $this->stats->load($page->getCollection());

        return MonitorResource::collection($page);
    }

    public function store(MonitorRequest $request): JsonResponse
    {
        $monitor = Monitor::create($request->monitorData() + ['accepted_statuses' => ['200-299']])->refresh();

        return $this->resource($monitor)->response()->setStatusCode(201);
    }

    public function show(Monitor $monitor): MonitorResource
    {
        return $this->resource($monitor);
    }

    public function update(MonitorRequest $request, Monitor $monitor): MonitorResource
    {
        $monitor->update($request->monitorData());
        // изменились настройки — проверим при ближайшем запуске планировщика
        if ($monitor->wasChanged() && $monitor->enabled) {
            $monitor->forceFill(['next_check_at' => null])->save();
        }

        return $this->resource($monitor);
    }

    public function destroy(Monitor $monitor): Response
    {
        $monitor->delete();

        return response()->noContent();
    }

    /** Разовая проверка по настройкам из тела, ничего не сохраняется. */
    public function test(MonitorRequest $request, MonitorChecker $checker): JsonResponse
    {
        $monitor = new Monitor($request->monitorData());
        $monitor->enabled = true;

        return response()->json($this->payload($checker->check($this->withDefaults($monitor))));
    }

    /** Проверить сейчас; результат записывается как обычная проверка. */
    public function check(Monitor $monitor, MonitorChecker $checker, MonitorRecorder $recorder): JsonResponse
    {
        $result = $checker->check($monitor);
        $recorder->record($monitor, $result);

        return response()->json([...$this->payload($result), 'monitor' => $this->resource($monitor->refresh())->resolve()]);
    }

    /**
     * Проверки за период, старые → новые. `24h` — все; для `7d` и `30d` усреднены по интервалам (час / 3 часа):
     * `down`, если в интервале была недоступность, иначе `up`; `message` в усреднённых — null.
     */
    public function heartbeats(Request $request, Monitor $monitor): JsonResponse
    {
        $period = $request->validate(['period' => ['nullable', 'in:24h,7d,30d']])['period'] ?? '24h';
        [$ago, $bucket] = self::PERIODS[$period];
        $from = Carbon::parse($ago);

        if ($bucket === 0) {
            $data = $monitor->heartbeats()->where('created_at', '>=', $from)->orderBy('created_at')->orderBy('id')->get()
                ->map(fn (MonitorHeartbeat $h) => [
                    'at' => $h->created_at,
                    'status' => $h->status,
                    'response_ms' => $h->response_ms,
                    'status_code' => $h->status_code,
                    'message' => $h->message,
                ]);
        } else {
            $data = $monitor->heartbeats()->where('created_at', '>=', $from)
                ->selectRaw('FLOOR(UNIX_TIMESTAMP(created_at) / ?) as b, SUM(status = ?) as up, SUM(status = ?) as down, AVG(response_ms) as ms', [$bucket, 'up', 'down'])
                ->groupBy('b')->orderBy('b')->get()
                ->map(fn ($r) => [
                    'at' => Carbon::createFromTimestampUTC((int) $r->b * $bucket),
                    'status' => $r->down > 0 ? 'down' : ($r->up > 0 ? 'up' : 'pending'),
                    'response_ms' => $r->ms !== null ? (int) round($r->ms) : null,
                    'status_code' => null,
                    'message' => null,
                ]);
        }

        return response()->json(['data' => $data->values()]);
    }

    /** Сбои монитора, новые сверху. */
    public function incidents(Request $request, Monitor $monitor): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return MonitorIncidentResource::collection(
            $monitor->incidents()->orderByDesc('started_at')->orderByDesc('id')->paginate($request->integer('per_page', 25)),
        );
    }

    private function resource(Monitor $monitor): MonitorResource
    {
        $monitor->load('project');
        $this->stats->load(collect([$monitor]));

        return new MonitorResource($monitor);
    }

    /** Значения по умолчанию для несохранённого монитора (как в БД). */
    private function withDefaults(Monitor $monitor): Monitor
    {
        return $monitor->forceFill(array_merge([
            'method' => 'GET', 'accepted_statuses' => ['200-299'], 'headers' => [], 'follow_redirects' => true,
            'ignore_tls' => false, 'keyword_invert' => false, 'ssl_expiry_days' => 14, 'dns_record_type' => 'A',
            'timeout' => 30,
        ], array_filter($monitor->getAttributes(), fn ($v) => $v !== null)));
    }

    /** @return array<string, mixed> */
    private function payload(CheckResult $r): array
    {
        return [
            'status' => $r->ok ? 'up' : 'down',
            'response_ms' => $r->responseMs,
            'status_code' => $r->statusCode,
            'message' => $r->message,
        ];
    }
}
