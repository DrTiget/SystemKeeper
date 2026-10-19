<?php

namespace Tests\Unit;

use App\Services\Metrics\MetricsException;
use App\Services\Metrics\MetricsParser;
use PHPUnit\Framework\TestCase;
use Tests\Support\MetricsSample;

class MetricsParserTest extends TestCase
{
    public function test_parses_modern_linux_sample(): void
    {
        $m = (new MetricsParser)->parse(MetricsSample::ubuntu());

        // за секунду выросло 566 тиков, из них простой (idle + iowait) — 410 → занято 27.6%
        $this->assertSame(27.6, $m['cpu']);
        $this->assertSame(4, $m['cpu_cores']);
        $this->assertSame([0.52, 0.61, 0.70], $m['load']);
        // used = MemTotal − MemAvailable
        $this->assertSame(['used' => 3_000_000 * 1024, 'total' => 8_000_000 * 1024], $m['memory']);
        $this->assertSame(['used' => 500_000 * 1024, 'total' => 2_000_000 * 1024], $m['swap']);
        $this->assertSame(1234568, $m['uptime']);
        $this->assertSame('Ubuntu 24.04.1 LTS', $m['os']);
        // lo, docker0 и veth не считаются; 204000 Б и 10200 Б за 1.02 с
        $this->assertSame(['rx' => 200000, 'tx' => 10000], $m['network']);
    }

    public function test_disks_skip_virtual_filesystems_and_duplicates(): void
    {
        $m = (new MetricsParser)->parse(MetricsSample::ubuntu());

        $this->assertSame(
            [
                ['mount' => '/', 'device' => '/dev/sda1', 'used' => 41_000_000_000, 'total' => 100_000_000_000],
                ['mount' => '/data', 'device' => '/dev/sdb1', 'used' => 450_000_000_000, 'total' => 500_000_000_000],
                ['mount' => '/boot/efi', 'device' => '/dev/sda15', 'used' => 6_000_000, 'total' => 104_000_000],
            ],
            $m['disks'],
        );
    }

    public function test_disks_exclude_by_exact_pattern_and_parent(): void
    {
        $parser = new MetricsParser;

        $this->assertSame(['/', '/data'], array_column($parser->parse(MetricsSample::ubuntu(), ['/boot'])['disks'], 'mount'));
        $this->assertSame(['/', '/boot/efi'], array_column($parser->parse(MetricsSample::ubuntu(), ['/dat*'])['disks'], 'mount'));
        $this->assertSame(['/', '/data', '/boot/efi'], array_column($parser->parse(MetricsSample::ubuntu(), ['/snap', ''])['disks'], 'mount'));
        // «/» скрывает только корень, а не всё
        $this->assertSame(['/data', '/boot/efi'], array_column($parser->parse(MetricsSample::ubuntu(), ['/'])['disks'], 'mount'));
    }

    public function test_parses_legacy_sample_without_memavailable_and_df_type(): void
    {
        $m = (new MetricsParser)->parse(MetricsSample::legacy());

        // выросло 300 тиков, простой — 200 → занято 33.3%
        $this->assertSame(33.3, $m['cpu']);
        $this->assertSame(1, $m['cpu_cores']);
        $this->assertSame([1.5, 1.0, 0.5], $m['load']);
        // MemFree + Buffers + Cached = 400000 kB свободно
        $this->assertSame(['used' => 600_000 * 1024, 'total' => 1_000_000 * 1024], $m['memory']);
        $this->assertSame(['used' => 0, 'total' => 0], $m['swap']);
        $this->assertNull($m['os']);
        $this->assertSame(['rx' => 1000, 'tx' => 500], $m['network']);
        $this->assertSame([['mount' => '/', 'device' => '/dev/vda1', 'used' => 5_000_000_000, 'total' => 20_000_000_000]], $m['disks']);
    }

    public function test_counter_reset_gives_zero_rate_not_negative(): void
    {
        $out = str_replace('eth0:4002000', 'eth0:100', MetricsSample::legacy());

        $this->assertSame(0, (new MetricsParser)->parse($out)['network']['rx']);
    }

    public function test_partial_output_gives_nulls(): void
    {
        $m = (new MetricsParser)->parse("#loadavg\n0.10 0.20 0.30 1/10 5\n#end\n");

        $this->assertSame([0.1, 0.2, 0.3], $m['load']);
        $this->assertNull($m['cpu']);
        $this->assertNull($m['memory']);
        $this->assertNull($m['network']);
        $this->assertSame([], $m['disks']);
    }

    public function test_garbage_output_throws(): void
    {
        $this->expectException(MetricsException::class);

        (new MetricsParser)->parse("sh: 1: grep: not found\n");
    }

    public function test_disk_percents(): void
    {
        $this->assertSame(['/' => 41.0, '/data' => 90.0], MetricsParser::diskPercents((new MetricsParser)->parse(MetricsSample::ubuntu(), ['/boot'])['disks']));
    }
}
