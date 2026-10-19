<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use Illuminate\Support\Facades\DB;

/**
 * Записывает результат проверки: heartbeat, новый статус монитора, открытие и закрытие сбоев.
 */
class MonitorRecorder
{
    public function record(Monitor $monitor, CheckResult $result): MonitorHeartbeat
    {
        return DB::transaction(function () use ($monitor, $result) {
            $now = now();
            [$status, $failures] = MonitorStatus::next($monitor->status, $monitor->failures, $monitor->retries, $result->ok);

            $heartbeat = $monitor->heartbeats()->create([
                'status' => $status,
                'response_ms' => $result->responseMs,
                'status_code' => $result->statusCode,
                'message' => $result->message,
                'created_at' => $now,
            ]);

            if ($status !== $monitor->status) {
                $monitor->status_since = $now;
            }
            $monitor->status = $status;
            $monitor->failures = $failures;
            $monitor->last_check_at = $now;
            $monitor->last_response_ms = $result->responseMs;
            $monitor->last_error = $result->ok ? null : $result->message;
            $monitor->next_check_at = $now->clone()->addSeconds(MonitorStatus::delay($status, $failures, $monitor->interval, $monitor->retry_interval));
            if ($monitor->type === Monitor::HTTP && ($result->sslValidTo || $result->ok)) {
                $monitor->ssl_valid_to = $result->sslValidTo;
                $monitor->ssl_issuer = $result->sslIssuer;
            }
            $monitor->save();

            $this->trackIncident($monitor, $status, $result);

            return $heartbeat;
        });
    }

    private function trackIncident(Monitor $monitor, string $status, CheckResult $result): void
    {
        $open = $monitor->incidents()->whereNull('ended_at');

        if ($status === Monitor::DOWN) {
            if (! $open->exists()) {
                $monitor->incidents()->create(['started_at' => now(), 'error' => $result->message]);
            }
        } elseif ($status === Monitor::UP) {
            $open->update(['ended_at' => now()]);
        }
    }
}
