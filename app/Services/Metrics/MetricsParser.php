<?php

namespace App\Services\Metrics;

/**
 * Разбирает вывод команды сбора метрик (см. MetricsCollector::COMMAND).
 * Рассчитан на Debian/Ubuntu/CentOS (GNU coreutils) и Alpine (BusyBox): незнакомые строки пропускаются.
 */
class MetricsParser
{
    /** Файловые системы, которые не показывают занятость реального диска. */
    private const SKIP_FS = [
        'tmpfs', 'devtmpfs', 'squashfs', 'proc', 'sysfs', 'cgroup', 'cgroup2', 'efivarfs', 'iso9660', 'devpts',
        'ramfs', 'overlayfs_lower', 'fuse.lxcfs', 'securityfs', 'debugfs', 'tracefs', 'configfs', 'mqueue',
    ];

    private const SKIP_MOUNT_PREFIXES = ['/proc', '/sys', '/dev', '/run', '/snap'];

    /** Файлы, которые Docker монтирует в контейнер с хоста: к диску сервера отношения не имеют. */
    private const SKIP_MOUNTS = ['/etc/hosts', '/etc/hostname', '/etc/resolv.conf'];

    /**
     * @return array{
     *     load1: float, load5: float, load15: float, cpu_count: int|null,
     *     mem_total: int, mem_used: int, mem_available: int, swap_total: int, swap_used: int,
     *     uptime_seconds: int|null,
     *     disks: list<array{mount: string, device: string, fstype: string|null, total: int, used: int, avail: int, inodes_total: int|null, inodes_used: int|null, inodes_free: int|null}>
     * }
     *
     * @throws MetricsParseException
     */
    public function parse(string $output): array
    {
        $sections = $this->sections($output);

        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)/', $sections['loadavg'] ?? '', $la)) {
            throw new MetricsParseException('Не удалось разобрать /proc/loadavg.');
        }
        $mem = $this->meminfo($sections['meminfo'] ?? '');

        $cpu = (int) trim($sections['nproc'] ?? '');
        $uptime = preg_match('/^\s*(\d+)/', $sections['uptime'] ?? '', $up) ? (int) $up[1] : null;

        return [
            'load1' => (float) $la[1],
            'load5' => (float) $la[2],
            'load15' => (float) $la[3],
            'cpu_count' => $cpu > 0 ? $cpu : null,
            ...$mem,
            'uptime_seconds' => $uptime,
            'disks' => $this->disks($sections['df'] ?? '', $sections['dfi'] ?? ''),
        ];
    }

    /** @return array<string, string> */
    private function sections(string $output): array
    {
        $result = [];
        $current = null;
        foreach (preg_split('/\R/', $output) as $line) {
            if (preg_match('/^@@SK:(\w+)\s*$/', $line, $m)) {
                $current = $m[1];
                $result[$current] = '';

                continue;
            }
            if ($current !== null) {
                $result[$current] .= $line."\n";
            }
        }

        return $result;
    }

    /** @return array{mem_total: int, mem_used: int, mem_available: int, swap_total: int, swap_used: int} */
    private function meminfo(string $text): array
    {
        $kb = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (preg_match('/^(\w+(?:\(\w+\))?):\s+(\d+)/', $line, $m)) {
                $kb[$m[1]] = (int) $m[2];
            }
        }
        if (empty($kb['MemTotal'])) {
            throw new MetricsParseException('Не удалось разобрать /proc/meminfo.');
        }

        $total = $kb['MemTotal'];
        // MemAvailable есть с ядра 3.14; на старых (CentOS 6) оцениваем сами
        $available = $kb['MemAvailable'] ?? (($kb['MemFree'] ?? 0) + ($kb['Buffers'] ?? 0) + ($kb['Cached'] ?? 0));
        $available = min($available, $total);
        $swapTotal = $kb['SwapTotal'] ?? 0;
        $swapFree = min($kb['SwapFree'] ?? 0, $swapTotal);

        return [
            'mem_total' => $total * 1024,
            'mem_used' => ($total - $available) * 1024,
            'mem_available' => $available * 1024,
            'swap_total' => $swapTotal * 1024,
            'swap_used' => ($swapTotal - $swapFree) * 1024,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function disks(string $df, string $dfi): array
    {
        $inodes = [];
        foreach ($this->dfRows($dfi) as $row) {
            $inodes[$row['mount']] = $row;
        }

        $disks = [];
        foreach ($this->dfRows($df) as $row) {
            if ($this->skip($row) || $row['total'] <= 0) {
                continue;
            }
            $inode = $inodes[$row['mount']] ?? null;
            $disks[] = [
                'mount' => $row['mount'],
                'device' => $row['device'],
                'fstype' => $row['fstype'],
                'total' => $row['total'] * 1024,
                'used' => $row['used'] * 1024,
                'avail' => $row['avail'] * 1024,
                'inodes_total' => $inode['total'] ?? null,
                'inodes_used' => $inode['used'] ?? null,
                'inodes_free' => $inode['avail'] ?? null,
            ];
        }

        return $this->dedupe($disks);
    }

    /**
     * Разбор вывода `df -P[T]`: тип ФС есть, только если в заголовке колонка «Type».
     *
     * @return list<array{device: string, fstype: string|null, total: int, used: int, avail: int, mount: string}>
     */
    private function dfRows(string $text): array
    {
        $lines = preg_split('/\R/', trim($text));
        $header = array_shift($lines) ?? '';
        $hasType = (bool) preg_match('/^\S+\s+Type\s/i', $header);
        $pattern = $hasType
            ? '/^(\S+)\s+(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(?:\d+%|-)\s+(\/.*)$/'
            : '/^(\S+)()\s+(\d+)\s+(\d+)\s+(\d+)\s+(?:\d+%|-)\s+(\/.*)$/';

        $rows = [];
        foreach ($lines as $line) {
            if (preg_match($pattern, rtrim($line), $m)) {
                $rows[] = [
                    'device' => $m[1], 'fstype' => $hasType ? $m[2] : null,
                    'total' => (int) $m[3], 'used' => (int) $m[4], 'avail' => (int) $m[5], 'mount' => $m[6],
                ];
            }
        }

        return $rows;
    }

    private function skip(array $row): bool
    {
        if ($row['fstype'] !== null && in_array($row['fstype'], self::SKIP_FS, true)) {
            return true;
        }
        if ($row['fstype'] === null && in_array($row['device'], ['tmpfs', 'devtmpfs', 'udev', 'shm', 'none', 'cgroup'], true)) {
            return true;
        }
        if (in_array($row['mount'], self::SKIP_MOUNTS, true)) {
            return true;
        }
        foreach (self::SKIP_MOUNT_PREFIXES as $prefix) {
            if ($row['mount'] === $prefix || str_starts_with($row['mount'], $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Одна и та же ФС, смонтированная в нескольких местах (bind-монтирования, /etc/hosts в контейнерах),
     * учитывается один раз — по самой короткой точке монтирования.
     *
     * @param  list<array<string, mixed>>  $disks
     * @return list<array<string, mixed>>
     */
    private function dedupe(array $disks): array
    {
        usort($disks, fn ($a, $b) => strlen($a['mount']) <=> strlen($b['mount']) ?: strcmp($a['mount'], $b['mount']));
        $seen = [];
        $result = [];
        foreach ($disks as $disk) {
            $key = implode('|', [$disk['device'], $disk['total'], $disk['used'], $disk['avail']]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $disk;
        }

        return $result;
    }
}
