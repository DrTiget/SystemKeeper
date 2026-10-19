<?php

namespace Tests\Support;

/**
 * Образцы вывода MetricsScript — как с настоящих серверов.
 */
class MetricsSample
{
    /** Ubuntu 24.04, 4 ядра, swap, диски с tmpfs, snap и bind-монтированием */
    public static function ubuntu(): string
    {
        return <<<'OUT'
#uptime1
1234567.89 4567890.12
#stat1
cpu  4705 150 3424 150000 1200 0 120 0 0 0
#net1
Inter-|   Receive                                                |  Transmit
 face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed
    lo: 9999999   100    0    0    0     0          0         0  9999999   100    0    0    0     0       0          0
  eth0: 1000000  5000    0    0    0     0          0         0   500000  4000    0    0    0     0       0          0
docker0:  777777   100    0    0    0     0          0         0   777777   100    0    0    0     0       0          0
veth1a2b:  555555   100    0    0    0     0          0         0   555555   100    0    0    0     0       0          0
#uptime2
1234568.91 4567891.00
#stat2
cpu  4805 150 3474 150400 1210 0 126 0 0 0
#net2
Inter-|   Receive                                                |  Transmit
 face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed
    lo: 10099999   110    0    0    0     0          0         0  10099999   110    0    0    0     0       0          0
  eth0: 1204000  5100    0    0    0     0          0         0   510200  4050    0    0    0     0       0          0
docker0:  877777   120    0    0    0     0          0         0   877777   120    0    0    0     0       0          0
veth1a2b:  655555   120    0    0    0     0          0         0   655555   120    0    0    0     0       0          0
#loadavg
0.52 0.61 0.70 2/512 98765
#meminfo
MemTotal:        8000000 kB
MemFree:          500000 kB
MemAvailable:    5000000 kB
Buffers:          200000 kB
Cached:          3000000 kB
SwapTotal:       2000000 kB
SwapFree:        1500000 kB
SReclaimable:     100000 kB
#os
NAME="Ubuntu"
VERSION="24.04.1 LTS (Noble Numbat)"
PRETTY_NAME="Ubuntu 24.04.1 LTS"
#nproc
4
#df
Filesystem     Type     1-blocks       Used  Available Capacity Mounted on
udev           devtmpfs  4000000000          0 4000000000       0% /dev
tmpfs          tmpfs      800000000    1000000  799000000       1% /run
/dev/sda1      ext4    100000000000 41000000000 59000000000      41% /
tmpfs          tmpfs     4000000000          0 4000000000       0% /dev/shm
/dev/sda1      ext4    100000000000 41000000000 59000000000      41% /var/lib/docker/overlay2
/dev/loop0     squashfs    64000000   64000000          0     100% /snap/core20/1974
/dev/sdb1      xfs    500000000000 450000000000 50000000000      90% /data
/dev/sda15     vfat      104000000    6000000  98000000       6% /boot/efi
#end
OUT;
    }

    /** Старый CentOS 6 / busybox: нет MemAvailable, df без -T */
    public static function legacy(): string
    {
        return <<<'OUT'
#uptime1
100.00 90.00
#stat1
cpu  100 0 100 800 0 0 0 0 0 0
#net1
Inter-|   Receive                                                |  Transmit
 face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed
  eth0:4000000  50 0 0 0 0 0 0 2000000 40 0 0 0 0 0 0
#uptime2
102.00 91.00
#stat2
cpu  150 0 150 1000 0 0 0 0 0 0
#net2
Inter-|   Receive                                                |  Transmit
 face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed
  eth0:4002000  60 0 0 0 0 0 0 2001000 50 0 0 0 0 0 0
#loadavg
1.50 1.00 0.50 1/100 1234
#meminfo
MemTotal:        1000000 kB
MemFree:          100000 kB
Buffers:           50000 kB
Cached:           250000 kB
SwapTotal:             0 kB
SwapFree:              0 kB
#os
#nproc
1
#df
Filesystem     1-blocks      Used Available Capacity Mounted on
/dev/vda1    20000000000 5000000000 15000000000      25% /
tmpfs          500000000         0   500000000       0% /dev/shm
#end
OUT;
    }
}
