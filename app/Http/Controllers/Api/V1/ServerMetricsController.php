<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Metrics\MetricsSettingsRequest;
use App\Http\Resources\ServerMetricsResource;
use App\Models\Server;
use App\Models\ServerMetricPoint;
use App\Models\ServerMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ресурсы серверов: CPU, память, диски, сеть — по SSH с уже настроенного подключения.
 */
class ServerMetricsController extends Controller
{
    /** Период истории → [секунд, размер интервала усреднения в секундах (0 — точки как есть)] */
    private const PERIODS = [
        '1h' => [3600, 0],
        '24h' => [86400, 300],
        '7d' => [604800, 2040],
        '30d' => [2592000, 8640],
    ];

    /** Сколько точек отдаётся в мини-график списка */
    private const SPARK_POINTS = 60;

    /** Текущие показатели всех серверов по названию. */
    public function index(): JsonResponse
    {
        $servers = Server::query()->with(['project', 'metrics'])->orderBy('name')->orderBy('id')->get();
        $spark = $this->spark($servers->pluck('id')->all());

        return response()->json(['data' => $servers
            ->map(fn (Server $s) => (new ServerMetricsResource($s))->withSpark($spark[$s->id] ?? ['cpu' => [], 'memory' => []])->resolve())
            ->values()]);
    }

    /** Текущие показатели сервера и настройки сбора. */
    public function show(Server $server): JsonResponse
    {
        $server->load(['project', 'metrics']);

        return $this->respond($server);
    }

    /** Настройки сбора. Включение и смена интервала действуют со следующей минуты. */
    public function update(MetricsSettingsRequest $request, Server $server): JsonResponse
    {
        $metrics = ServerMetrics::query()->firstOrCreate(['server_id' => $server->id]);
        $metrics->fill($request->validated());
        if ($metrics->isDirty(['enabled', 'interval'])) {
            $metrics->next_collect_at = null;
        }
        $metrics->save();

        return $this->respond($server->load('project')->setRelation('metrics', $metrics));
    }

    /**
     * История: `period` — 1h, 24h (по умолчанию), 7d, 30d. Старые → новые; на длинных периодах значения усредняются
     * по интервалам, чтобы точек было около 300 (заполненность дисков — по последней точке интервала).
     */
    public function history(Request $request, Server $server): JsonResponse
    {
        $request->validate(['period' => ['nullable', 'in:'.implode(',', array_keys(self::PERIODS))]]);
        [$seconds, $bucket] = self::PERIODS[$request->input('period', '24h')];
        $since = now()->subSeconds($seconds);

        $points = $bucket === 0 ? $this->rawPoints($server, $since) : $this->bucketedPoints($server, $since, $bucket);

        return response()->json(['data' => $points]);
    }

    private function respond(Server $server): JsonResponse
    {
        $resource = (new ServerMetricsResource($server))->withSpark($this->spark([$server->id])[$server->id] ?? ['cpu' => [], 'memory' => []]);

        return response()->json(['data' => $resource->resolve()]);
    }

    /** @return list<array<string, mixed>> */
    private function rawPoints(Server $server, Carbon $since): array
    {
        return ServerMetricPoint::query()->where('server_id', $server->id)->where('created_at', '>=', $since)
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (ServerMetricPoint $p) => [
                'at' => $p->created_at->toIso8601String(),
                'cpu' => $p->cpu,
                'memory' => $p->memory,
                'swap' => $p->swap,
                'load1' => $p->load1,
                'rx' => $p->rx,
                'tx' => $p->tx,
                'disks' => (object) ($p->disks ?? []),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function bucketedPoints(Server $server, Carbon $since, int $bucket): array
    {
        $key = "FLOOR(UNIX_TIMESTAMP(created_at) / {$bucket})";
        $rows = DB::table('server_metric_points')->where('server_id', $server->id)->where('created_at', '>=', $since)
            ->groupByRaw($key)->orderByRaw("{$key}")
            ->selectRaw('MIN(created_at) as at, AVG(cpu) as cpu, AVG(memory) as memory, AVG(swap) as swap, AVG(load1) as load1, AVG(rx) as rx, AVG(tx) as tx, MAX(id) as last_id')
            ->get();

        $disks = DB::table('server_metric_points')->whereIn('id', $rows->pluck('last_id'))->pluck('disks', 'id');
        $num = fn ($v, int $digits = 2) => $v === null ? null : round((float) $v, $digits);

        return $rows->map(fn ($r) => [
            'at' => Carbon::parse($r->at, config('app.timezone'))->toIso8601String(),
            'cpu' => $num($r->cpu),
            'memory' => $num($r->memory),
            'swap' => $num($r->swap),
            'load1' => $num($r->load1),
            'rx' => $r->rx === null ? null : (int) round((float) $r->rx),
            'tx' => $r->tx === null ? null : (int) round((float) $r->tx),
            'disks' => (object) (json_decode((string) ($disks[$r->last_id] ?? ''), true) ?: []),
        ])->all();
    }

    /**
     * CPU и память за последний час — для мини-графиков, не больше SPARK_POINTS значений на сервер.
     *
     * @param  list<int>  $serverIds
     * @return array<int, array{cpu: list<float>, memory: list<float>}>
     */
    private function spark(array $serverIds): array
    {
        $rows = ServerMetricPoint::query()->whereIn('server_id', $serverIds)->where('created_at', '>=', now()->subHour())
            ->orderBy('created_at')->orderBy('id')->get(['server_id', 'cpu', 'memory'])->groupBy('server_id');

        $result = [];
        foreach ($rows as $id => $points) {
            $step = max(1, (int) ceil($points->count() / self::SPARK_POINTS));
            $points = $points->values()->filter(fn ($p, $i) => $i % $step === 0);
            $result[$id] = [
                'cpu' => $points->pluck('cpu')->filter(fn ($v) => $v !== null)->values()->all(),
                'memory' => $points->pluck('memory')->filter(fn ($v) => $v !== null)->values()->all(),
            ];
        }

        return $result;
    }
}
