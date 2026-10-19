<?php

namespace App\Services\Monitoring;

use App\Models\Monitor;

/**
 * Расчёт статуса монитора и uptime — чистые функции без БД.
 */
final class MonitorStatus
{
    /**
     * Следующее состояние после проверки: [статус, неудач подряд].
     * Успех — `up`. Неудача — `pending`, пока подряд не набралось больше `$retries` неудач (0 — недоступен сразу);
     * уже недоступный монитор остаётся `down` до первого успеха.
     *
     * @return array{0: string, 1: int}
     */
    public static function next(string $current, int $failures, int $retries, bool $ok): array
    {
        if ($ok) {
            return [Monitor::UP, 0];
        }

        $failures++;

        return [$current === Monitor::DOWN || $failures > $retries ? Monitor::DOWN : Monitor::PENDING, $failures];
    }

    /** Доля успешных проверок, % (0…100, до сотых); проверки со статусом `pending` не считаются; null — нет данных. */
    public static function uptime(int $up, int $down): ?float
    {
        $total = $up + $down;

        return $total === 0 ? null : round($up / $total * 100, 2);
    }

    /** Через сколько секунд проверять снова: после неудачи — по `retry_interval`, иначе по `interval`. */
    public static function delay(string $status, int $failures, int $interval, int $retryInterval): int
    {
        return $status === Monitor::PENDING && $failures > 0 ? $retryInterval : $interval;
    }

    /** Сколько целых дней осталось до даты (отрицательно — уже истёк). */
    public static function daysLeft(\DateTimeInterface $validTo, ?\DateTimeInterface $now = null): int
    {
        $diff = ($validTo->getTimestamp() - ($now ?? new \DateTimeImmutable)->getTimestamp()) / 86400;

        return (int) floor($diff);
    }
}
