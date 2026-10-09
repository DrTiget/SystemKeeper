<?php

namespace Tests\Unit;

use App\Services\Metrics\MetricsParseException;
use App\Services\Metrics\MetricsParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\MetricsFixtures;

class MetricsParserTest extends TestCase
{
    public function test_parses_debian_output(): void
    {
        $m = (new MetricsParser)->parse(MetricsFixtures::debian());

        $this->assertSame(0.52, $m['load1']);
        $this->assertSame(0.40, $m['load5']);
        $this->assertSame(0.30, $m['load15']);
        $this->assertSame(4, $m['cpu_count']);
        $this->assertSame(8000000 * 1024, $m['mem_total']);
        $this->assertSame(6000000 * 1024, $m['mem_available']);
        $this->assertSame(2000000 * 1024, $m['mem_used']);
        $this->assertSame(1000000 * 1024, $m['swap_total']);
        $this->assertSame(250000 * 1024, $m['swap_used']);
        $this->assertSame(266400, $m['uptime_seconds']);

        // devtmpfs/tmpfs отброшены, bind-дубль /etc/hosts схлопнут, точка монтирования с пробелом сохранена
        $this->assertSame(['/', '/var/lib/my data'], array_column($m['disks'], 'mount'));
        $root = $m['disks'][0];
        $this->assertSame('/dev/sda1', $root['device']);
        $this->assertSame('ext4', $root['fstype']);
        $this->assertSame(100000000 * 1024, $root['total']);
        $this->assertSame(70000000 * 1024, $root['avail']);
        $this->assertSame(6400000, $root['inodes_total']);
        $this->assertSame(200000, $root['inodes_used']);
        $this->assertSame(3199000, $m['disks'][1]['inodes_free']);
    }

    public function test_parses_alpine_busybox_df_without_type(): void
    {
        $m = (new MetricsParser)->parse(MetricsFixtures::alpine());

        $this->assertSame(2, $m['cpu_count']);
        $this->assertSame(0, $m['swap_total']);
        $this->assertSame(0, $m['swap_used']);
        $this->assertCount(1, $m['disks']);
        $this->assertSame('/', $m['disks'][0]['mount']);
        $this->assertNull($m['disks'][0]['fstype']);
        $this->assertSame(550000, $m['disks'][0]['inodes_free']);
    }

    public function test_parses_old_centos_without_memavailable_and_inodes(): void
    {
        $m = (new MetricsParser)->parse(MetricsFixtures::centos6());

        // доступно = free + buffers + cached
        $this->assertSame(1000000 * 1024, $m['mem_available']);
        $this->assertSame(1000000 * 1024, $m['mem_used']);
        $this->assertSame('/dev/mapper/vg-root', $m['disks'][0]['device']);
        $this->assertNull($m['disks'][0]['inodes_total']);
    }

    public function test_garbage_output_raises(): void
    {
        $this->expectException(MetricsParseException::class);

        (new MetricsParser)->parse("bash: cat: command not found\n");
    }

    public function test_missing_meminfo_raises(): void
    {
        $this->expectException(MetricsParseException::class);

        (new MetricsParser)->parse("@@SK:loadavg\n0.1 0.1 0.1 1/1 1\n@@SK:meminfo\n");
    }
}
