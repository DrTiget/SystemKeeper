<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorRecorder;
use Illuminate\Http\JsonResponse;

/** Публичный приём пингов от заданий cron и подобных: авторизация — сам токен в URL. */
class HeartbeatController extends Controller
{
    public function ping(string $token, MonitorRecorder $recorder): JsonResponse
    {
        $monitor = Monitor::query()->where('type', Monitor::TYPE_HEARTBEAT)->where('heartbeat_token', $token)->first();
        abort_if($monitor === null, 404, 'Монитор не найден');

        // Пауза: пинг принимаем, но состояние не меняем
        if (! $monitor->enabled) {
            return response()->json(['data' => ['ok' => true, 'paused' => true]]);
        }

        $recorder->heartbeat($monitor);

        return response()->json(['data' => ['ok' => true, 'paused' => false]]);
    }
}
