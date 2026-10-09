<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Выполняет одну проверку монитора и возвращает результат; в БД ничего не пишет. */
class MonitorChecker
{
    public function __construct(private readonly NetworkProbe $probe) {}

    public function check(Monitor $monitor): CheckResult
    {
        $started = hrtime(true);

        try {
            $result = match ($monitor->type) {
                Monitor::TYPE_HTTP, Monitor::TYPE_KEYWORD => $this->http($monitor),
                Monitor::TYPE_TCP => $this->tcp($monitor),
                Monitor::TYPE_PING => $this->ping($monitor),
                Monitor::TYPE_SSL => $this->ssl($monitor),
                Monitor::TYPE_HEARTBEAT => $this->heartbeat($monitor),
                default => CheckResult::down("Неизвестный тип монитора «{$monitor->type}»"),
            };
        } catch (MonitorProbeException $e) {
            $result = CheckResult::down($e->getMessage());
        } catch (Throwable $e) {
            $result = CheckResult::down($e->getMessage());
        }

        if ($result->responseTimeMs === null && in_array($monitor->type, [Monitor::TYPE_HTTP, Monitor::TYPE_KEYWORD, Monitor::TYPE_TCP, Monitor::TYPE_PING], true)) {
            $result = new CheckResult($result->up, (int) round((hrtime(true) - $started) / 1e6), $result->statusCode, $result->error);
        }

        return $result;
    }

    private function http(Monitor $monitor): CheckResult
    {
        $response = Http::timeout($monitor->timeout_seconds)->withHeaders(['User-Agent' => 'SystemKeeper-Monitor'])->get((string) $monitor->target);
        $status = $response->status();
        $ms = (int) round(($response->handlerStats()['total_time'] ?? 0) * 1000) ?: null;

        $statusOk = $monitor->expected_status !== null ? $status === $monitor->expected_status : $response->successful();
        if (! $statusOk) {
            $expected = $monitor->expected_status !== null ? "ожидался {$monitor->expected_status}" : 'ожидался 2xx';

            return CheckResult::down("HTTP {$status} ({$expected})", $ms, $status);
        }

        if ($monitor->type === Monitor::TYPE_KEYWORD && ! str_contains($response->body(), (string) $monitor->keyword)) {
            return CheckResult::down("Ключевое слово «{$monitor->keyword}» не найдено", $ms, $status);
        }

        return CheckResult::up($ms, $status);
    }

    private function tcp(Monitor $monitor): CheckResult
    {
        [$host, $port] = $monitor->hostPort(0);
        $this->probe->tcp($host, $port, $monitor->timeout_seconds);

        return CheckResult::up();
    }

    private function ping(Monitor $monitor): CheckResult
    {
        $this->probe->ping((string) $monitor->target, $monitor->timeout_seconds);

        return CheckResult::up();
    }

    private function ssl(Monitor $monitor): CheckResult
    {
        [$host, $port] = $monitor->hostPort(443);
        $expiresAt = $this->probe->sslExpiresAt($host, $port, $monitor->timeout_seconds);
        $days = (int) floor(now()->diffInSeconds($expiresAt, false) / 86400);

        if ($days < 0) {
            return CheckResult::down('Сертификат истёк '.$expiresAt->toDateString());
        }
        if ($days < $monitor->ssl_expiry_days) {
            return CheckResult::down("Сертификат истекает {$expiresAt->toDateString()} (осталось дней: {$days}, порог: {$monitor->ssl_expiry_days})");
        }

        return CheckResult::up();
    }

    private function heartbeat(Monitor $monitor): CheckResult
    {
        $reference = $monitor->last_heartbeat_at;
        if ($monitor->enabled_at !== null && ($reference === null || $monitor->enabled_at->gt($reference))) {
            $reference = $monitor->enabled_at;
        }
        $reference ??= $monitor->created_at;

        $limit = $monitor->interval_seconds + $monitor->heartbeat_grace_seconds;
        $silence = (int) $reference->diffInSeconds(now(), true);

        if ($silence > $limit) {
            return CheckResult::down("Heartbeat не поступал {$silence} с (допустимо {$limit} с)");
        }

        return CheckResult::up();
    }
}
