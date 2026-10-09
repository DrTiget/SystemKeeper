<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServerMetricResource;
use App\Models\Server;
use App\Models\ServerMetricState;
use App\Services\Metrics\MetricsCollector;
use App\Services\Metrics\MetricsHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ServerMetricController extends Controller
{
    /** Последний замер и активные превышения порогов. */
    public function latest(Server $server): JsonResponse
    {
        $metric = $server->metrics()->latest('collected_at')->latest('id')->first();

        return response()->json(['data' => [
            'metric' => $metric ? new ServerMetricResource($metric) : null,
            'alerts' => $this->alerts($server),
            'collected_at' => $server->metrics_collected_at,
            'last_error' => $server->metrics_last_error,
        ]]);
    }

    /** История за период, усреднённая по окнам: ?from=&to=&points=. */
    public function history(Request $request, Server $server, MetricsHistory $history): JsonResponse
    {
        $retention = (int) config('metrics.retention_days');
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'points' => ['nullable', 'integer', 'between:2,1000'],
        ], [], ['from' => 'начало периода', 'to' => 'конец периода', 'points' => 'число точек']);

        $to = isset($data['to']) ? now()->parse($data['to']) : now();
        $from = isset($data['from']) ? now()->parse($data['from']) : $to->copy()->subDay();
        if ($from >= $to) {
            return response()->json(['message' => 'Начало периода должно быть раньше конца.', 'errors' => ['from' => ['Начало периода должно быть раньше конца.']]], 422);
        }
        if ($from < now()->subDays($retention)) {
            $from = now()->subDays($retention);
        }

        return response()->json(['data' => $history->aggregate($server, $from, $to, (int) ($data['points'] ?? 200))]);
    }

    /** Собрать метрики прямо сейчас (синхронно). */
    public function collect(Server $server, MetricsCollector $collector): JsonResponse
    {
        try {
            $metric = $collector->collect($server);
        } catch (Throwable $e) {
            return response()->json(['data' => ['status' => 'failed', 'error' => $server->refresh()->metrics_last_error ?? $e->getMessage(), 'metric' => null]]);
        }

        return response()->json(['data' => ['status' => 'ok', 'error' => null, 'metric' => new ServerMetricResource($metric)]]);
    }

    /** @return list<array<string, mixed>> */
    private function alerts(Server $server): array
    {
        return $server->metricStates()->where('state', ServerMetricState::STATE_EXCEEDED)->orderBy('metric')->orderBy('target')->get()
            ->map(fn (ServerMetricState $s) => [
                'metric' => $s->metric,
                'target' => $s->target === '' ? null : $s->target,
                'value' => $s->value,
                'threshold' => $s->threshold,
                'exceeded_since' => $s->exceeded_since,
            ])->all();
    }
}
