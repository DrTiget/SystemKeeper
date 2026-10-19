<?php

namespace Tests\Unit;

use App\Models\Monitor;
use App\Services\Monitoring\MonitorStatus;
use PHPUnit\Framework\TestCase;

class MonitorStatusTest extends TestCase
{
    public function test_success_resets_failures(): void
    {
        $this->assertSame(['up', 0], MonitorStatus::next(Monitor::DOWN, 5, 2, true));
        $this->assertSame(['up', 0], MonitorStatus::next(Monitor::PENDING, 1, 2, true));
    }

    public function test_down_only_after_more_failures_than_retries(): void
    {
        $this->assertSame(['pending', 1], MonitorStatus::next(Monitor::UP, 0, 2, false));
        $this->assertSame(['pending', 2], MonitorStatus::next(Monitor::PENDING, 1, 2, false));
        $this->assertSame(['down', 3], MonitorStatus::next(Monitor::PENDING, 2, 2, false));
    }

    public function test_zero_retries_means_down_immediately(): void
    {
        $this->assertSame(['down', 1], MonitorStatus::next(Monitor::UP, 0, 0, false));
    }

    public function test_down_stays_down_until_success(): void
    {
        $this->assertSame(['down', 8], MonitorStatus::next(Monitor::DOWN, 7, 10, false));
    }

    public function test_uptime_percent(): void
    {
        $this->assertNull(MonitorStatus::uptime(0, 0));
        $this->assertSame(100.0, MonitorStatus::uptime(10, 0));
        $this->assertSame(0.0, MonitorStatus::uptime(0, 3));
        $this->assertSame(66.67, MonitorStatus::uptime(2, 1));
        $this->assertSame(99.9, MonitorStatus::uptime(999, 1));
    }

    public function test_delay_uses_retry_interval_only_while_retrying(): void
    {
        $this->assertSame(30, MonitorStatus::delay(Monitor::PENDING, 1, 300, 30));
        $this->assertSame(300, MonitorStatus::delay(Monitor::UP, 0, 300, 30));
        $this->assertSame(300, MonitorStatus::delay(Monitor::DOWN, 4, 300, 30));
    }

    public function test_days_left(): void
    {
        $now = new \DateTimeImmutable('2026-10-16 12:00:00');
        $this->assertSame(9, MonitorStatus::daysLeft(new \DateTimeImmutable('2026-10-26 00:00:00'), $now));
        $this->assertSame(-1, MonitorStatus::daysLeft(new \DateTimeImmutable('2026-10-16 00:00:00'), $now));
    }
}
