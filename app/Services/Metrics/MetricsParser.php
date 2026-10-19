<?php

namespace App\Services\Metrics;

/**
 * Разбирает вывод MetricsScript в показатели сервера. Недостающие разделы дают null, но если не разобрано ничего —
 * это не Linux или скрипт не сработал: бросаем MetricsException.
 */
class MetricsParser
{
    /** Файловые системы, которые не диски: память, контейнеры, служебные */
    private const SKIP_FS = [
        'tmpfs', 'devtmpfs', 'devfs', 'overlay', 'squashfs', 'proc', 'sysfs', 'cgroup', 'cgroup2', 'ramfs', 'shm',
        'efivarfs', 'iso9660', 'aufs', 'fuse.lxcfs', 'fuse.gvfsd-fuse', 'autofs', 'nsfs', 'securityfs',
    ];

    /** Сетевые интерфейсы, трафик которых дублируется или не внешний: петля, контейнеры, мосты */
    private const SKIP_IFACE = '/^(lo|veth|docker|br-|virbr|cni|flannel|cali|tun|tap)/';

    /**
     * @param  list<string>  $disksExclude  точки монтирования: точное совпадение, шаблон (`/snap/*`) или родитель (`/snap` скрывает `/snap/core`)
     * @return array{cpu: float|null, cpu_cores: int|null, load: array<int, float>|null, memory: array{used: int, total: int}|null, swap: array{used: int, total: int}|null, disks: list<array{mount: string, device: string|null, used: int, total: int}>, network: array{rx: int, tx: int}|null, uptime: int|null, os: string|null}
     */
    public function parse(string $output, array $disksExclude = []): array
    {
        $s = $this->sections($output);

        $cpu = $this->cpu($s['stat1'] ?? '', $s['stat2'] ?? '');
        $load = $this->load($s['loadavg'] ?? '');
        $mem = $this->memory($s['meminfo'] ?? '');

        if ($cpu === null && $load === null && $mem['memory'] === null) {
            throw new MetricsException('Не удалось разобрать показатели сервера: ожидается Linux с /proc.');
        }

        $uptime1 = $this->uptime($s['uptime1'] ?? '');
        $uptime2 = $this->uptime($s['uptime2'] ?? '');
        $cores = (int) trim($s['nproc'] ?? '');

        return [
            'cpu' => $cpu,
            'cpu_cores' => $cores > 0 ? $cores : null,
            'load' => $load,
            'memory' => $mem['memory'],
            'swap' => $mem['swap'],
            'disks' => $this->disks($s['df'] ?? '', $disksExclude),
            'network' => $this->network($s['net1'] ?? '', $s['net2'] ?? '', $uptime1 !== null && $uptime2 !== null ? $uptime2 - $uptime1 : 1.0),
            'uptime' => $uptime2 === null ? null : (int) $uptime2,
            'os' => $this->os($s['os'] ?? ''),
        ];
    }

    /** Заполненность дисков, % по точке монтирования — для точек истории. */
    public static function diskPercents(array $disks): array
    {
        $result = [];
        foreach ($disks as $disk) {
            if ($disk['total'] > 0) {
                $result[$disk['mount']] = round($disk['used'] / $disk['total'] * 100, 1);
            }
        }

        return $result;
    }

    /** @return array<string, string> */
    private function sections(string $output): array
    {
        $sections = [];
        $name = null;
        foreach (preg_split('/\r?\n/', $output) as $line) {
            if (preg_match('/^#([a-z0-9]+)$/', $line, $m)) {
                $name = $m[1];
                $sections[$name] = '';
            } elseif ($name !== null && $name !== 'end') {
                $sections[$name] .= $line."\n";
            }
        }

        return $sections;
    }

    /** Загрузка CPU, %: доля неidle-времени между двумя замерами /proc/stat. */
    private function cpu(string $a, string $b): ?float
    {
        $first = $this->cpuTimes($a);
        $second = $this->cpuTimes($b);
        if ($first === null || $second === null) {
            return null;
        }
        $total = $second['total'] - $first['total'];
        if ($total <= 0) {
            return null;
        }
        $idle = $second['idle'] - $first['idle'];

        return round(min(100, max(0, (1 - $idle / $total) * 100)), 1);
    }

    /** @return array{total: int, idle: int}|null */
    private function cpuTimes(string $line): ?array
    {
        $f = preg_split('/\s+/', trim($line));
        if (($f[0] ?? '') !== 'cpu' || count($f) < 5) {
            return null;
        }
        // user nice system idle iowait irq softirq steal — guest уже входит в user
        $values = array_map('intval', array_slice($f, 1, 8));

        return ['total' => array_sum($values), 'idle' => $values[3] + ($values[4] ?? 0)];
    }

    /** @return array<int, float>|null */
    private function load(string $text): ?array
    {
        $f = preg_split('/\s+/', trim($text));

        return count($f) >= 3 && is_numeric($f[0]) && is_numeric($f[1]) && is_numeric($f[2])
            ? [(float) $f[0], (float) $f[1], (float) $f[2]]
            : null;
    }

    /** @return array{memory: array{used: int, total: int}|null, swap: array{used: int, total: int}|null} */
    private function memory(string $text): array
    {
        $kb = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $kb[$m[1]] = (int) $m[2] * 1024;
            }
        }
        if (empty($kb['MemTotal'])) {
            return ['memory' => null, 'swap' => null];
        }

        // MemAvailable есть с ядра 3.14; на старых — свободная + буферы + кеш
        $available = $kb['MemAvailable'] ?? (($kb['MemFree'] ?? 0) + ($kb['Buffers'] ?? 0) + ($kb['Cached'] ?? 0) + ($kb['SReclaimable'] ?? 0));
        $swapTotal = $kb['SwapTotal'] ?? 0;

        return [
            'memory' => ['used' => max(0, $kb['MemTotal'] - $available), 'total' => $kb['MemTotal']],
            'swap' => ['used' => max(0, $swapTotal - ($kb['SwapFree'] ?? 0)), 'total' => $swapTotal],
        ];
    }

    private function uptime(string $text): ?float
    {
        $f = preg_split('/\s+/', trim($text));

        return isset($f[0]) && is_numeric($f[0]) ? (float) $f[0] : null;
    }

    private function os(string $text): ?string
    {
        $values = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(PRETTY_NAME|NAME|VERSION)=(.*)$/', trim($line), $m)) {
                $values[$m[1]] = trim($m[2], "\"' ");
            }
        }

        return ($values['PRETTY_NAME'] ?? trim(($values['NAME'] ?? '').' '.($values['VERSION'] ?? ''))) ?: null;
    }

    /**
     * @param  list<string>  $exclude
     * @return list<array{mount: string, device: string|null, used: int, total: int}>
     */
    private function disks(string $text, array $exclude): array
    {
        $rows = [];
        foreach (array_slice(explode("\n", trim($text)), 1) as $line) {
            $f = preg_split('/\s+/', trim($line));
            // с `df -T`: устройство, тип, размер, занято, свободно, %, точка; без него — без типа
            $typed = count($f) >= 7 && ! is_numeric($f[1]);
            if (count($f) < ($typed ? 7 : 6)) {
                continue;
            }
            $offset = $typed ? 1 : 0;
            [$device, $total, $used] = [$f[0], $f[1 + $offset], $f[2 + $offset]];
            $mount = implode(' ', array_slice($f, 5 + $offset));
            if (! is_numeric($total) || ! is_numeric($used) || (float) $total <= 0 || $mount === '') {
                continue;
            }
            if (($typed && in_array($f[1], self::SKIP_FS, true)) || ! $typed && in_array($device, ['tmpfs', 'devtmpfs', 'overlay', 'shm', 'none', 'udev'], true)) {
                continue;
            }
            $rows[] = ['mount' => $mount, 'device' => $device, 'used' => (int) $used, 'total' => (int) $total];
        }

        // одно устройство, смонтированное в нескольких местах (bind, subvolume), показываем один раз — по самому короткому пути
        usort($rows, fn ($x, $y) => strlen($x['mount']) <=> strlen($y['mount']) ?: strcmp($x['mount'], $y['mount']));
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = $row['device'].'|'.$row['total'];
            if (isset($seen[$key]) && str_starts_with((string) $row['device'], '/')) {
                continue;
            }
            $seen[$key] = true;
            // исключения — после склейки: скрытый «/» не должен открывать его bind-копии
            if (! $this->excluded($row['mount'], $exclude)) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /** @param list<string> $exclude */
    private function excluded(string $mount, array $exclude): bool
    {
        foreach ($exclude as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }
            if ($mount === $pattern || fnmatch($pattern, $mount)) {
                return true;
            }
            if ($pattern !== '/' && str_starts_with($mount, rtrim($pattern, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /** Скорость сети, байт/с, по сумме внешних интерфейсов между двумя замерами /proc/net/dev. */
    private function network(string $a, string $b, float $seconds): ?array
    {
        $first = $this->netTotals($a);
        $second = $this->netTotals($b);
        if ($first === null || $second === null) {
            return null;
        }
        $seconds = max($seconds, 0.5);

        // счётчик мог сброситься или переполниться — тогда отрицательную дельту считаем нулём
        return [
            'rx' => (int) round(max(0, $second['rx'] - $first['rx']) / $seconds),
            'tx' => (int) round(max(0, $second['tx'] - $first['tx']) / $seconds),
        ];
    }

    /** @return array{rx: int, tx: int}|null */
    private function netTotals(string $text): ?array
    {
        $rx = $tx = 0;
        $found = false;
        foreach (explode("\n", $text) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$iface, $data] = explode(':', $line, 2);
            $iface = trim($iface);
            $f = preg_split('/\s+/', trim($data));
            if (preg_match(self::SKIP_IFACE, $iface) || count($f) < 9 || ! ctype_digit($f[0]) || ! ctype_digit($f[8])) {
                continue;
            }
            $rx += (int) $f[0];
            $tx += (int) $f[8];
            $found = true;
        }

        return $found ? ['rx' => $rx, 'tx' => $tx] : null;
    }
}
