<?php

namespace Tests\Support;

/** Образцы вывода MetricsCollector::COMMAND на разных дистрибутивах. */
class MetricsFixtures
{
    public static function debian(string $rootUsed = '30000000', string $rootAvail = '70000000', string $load = '0.52 0.40 0.30 1/200 12345', string $memAvailable = '6000000'): string
    {
        return <<<OUT
@@SK:loadavg
$load
@@SK:meminfo
MemTotal:       8000000 kB
MemFree:         500000 kB
MemAvailable:   $memAvailable kB
Buffers:         100000 kB
Cached:         2000000 kB
SwapTotal:      1000000 kB
SwapFree:        750000 kB
@@SK:nproc
4
@@SK:uptime
266400.55 900000.10
@@SK:df
Filesystem     Type     1024-blocks     Used Available Capacity Mounted on
udev           devtmpfs     4000000        0   4000000       0% /dev
tmpfs          tmpfs         800000     1000    799000       1% /run
/dev/sda1      ext4       100000000 $rootUsed $rootAvail      30% /
/dev/sda2      ext4        50000000 10000000  40000000      20% /var/lib/my data
/dev/sda1      ext4       100000000 $rootUsed $rootAvail      30% /etc/hosts
@@SK:dfi
Filesystem       Inodes  IUsed    IFree IUse% Mounted on
udev             1000000    400   999600    1% /dev
/dev/sda1        6400000 200000  6200000    4% /
/dev/sda2        3200000   1000  3199000    1% /var/lib/my data
/dev/sda1        6400000 200000  6200000    4% /etc/hosts

OUT;
    }

    /** Alpine: BusyBox df без колонки Type, `df -PTk` не поддерживается — сработал запасной `df -Pk`. */
    public static function alpine(): string
    {
        return <<<'OUT'
@@SK:loadavg
1.00 0.50 0.25 2/100 999
@@SK:meminfo
MemTotal:        1000000 kB
MemFree:          300000 kB
MemAvailable:     600000 kB
SwapTotal:             0 kB
SwapFree:              0 kB
@@SK:nproc
2
@@SK:uptime
120.00 100.00
@@SK:df
Filesystem           1024-blocks    Used Available Capacity Mounted on
overlay                 10000000 4000000   6000000  40% /
tmpfs                      65536       0     65536   0% /dev
shm                        65536       0     65536   0% /dev/shm
/dev/vda1               10000000 4000000   6000000  40% /etc/resolv.conf
@@SK:dfi
Filesystem              Inodes      Used Available Capacity Mounted on
overlay                 600000     50000    550000   8% /

OUT;
    }

    /** CentOS 6: нет MemAvailable, нет nproc (запасной grep вернул число процессоров), нет df -i. */
    public static function centos6(): string
    {
        return <<<'OUT'
@@SK:loadavg
0.00 0.01 0.05 1/90 1234
@@SK:meminfo
MemTotal:        2000000 kB
MemFree:          200000 kB
Buffers:          100000 kB
Cached:           700000 kB
SwapTotal:       1000000 kB
SwapFree:        1000000 kB
@@SK:nproc
1
@@SK:uptime
5000.12 4000.00
@@SK:df
Filesystem     Type 1024-blocks    Used Available Capacity Mounted on
/dev/mapper/vg-root ext4 20000000 5000000  15000000      25% /
@@SK:dfi

OUT;
    }
}
