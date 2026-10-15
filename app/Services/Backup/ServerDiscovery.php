<?php

namespace App\Services\Backup;

use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Services\Ssh\SshConnectorInterface;
use RuntimeException;

/**
 * Что бекапить на сервере: по SSH проверяются популярные места (настройки, сайты, приложения, compose-проекты,
 * почта, сертификаты) и работающие СУБД — локальные и в Docker. Только чтение.
 */
class ServerDiscovery
{
    /** Категории, каталоги которых предлагаются сразу; тома Docker — нет (там обычно файлы баз) */
    private const RECOMMENDED = ['system', 'apps', 'web', 'docker', 'mail'];

    public function __construct(private SshConnectorInterface $ssh) {}

    /**
     * @return array{
     *     info: array<string, string>,
     *     paths: list<array{path: string, category: string, reasons: list<string>, inside: ?string, recommended: bool, size: ?int}>,
     *     excludes: list<array{pattern: string, reason: string}>,
     *     databases: list<array{engine: string, where: string, container: ?string, detail: string, supported: bool, added: bool}>
     * }
     */
    public function discover(Server $server): array
    {
        $result = $this->ssh->exec($server, 'sh -s', 120, (string) file_get_contents(resource_path('scripts/discover.sh')));
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Не удалось осмотреть сервер: '.trim($result['stderr'] ?: $result['stdout']));
        }

        $info = $paths = $excludes = $databases = [];
        foreach (explode("\n", $result['stdout']) as $line) {
            $f = explode("\t", rtrim($line, "\r"));
            match ($f[0]) {
                'I' => count($f) >= 3 && $f[2] !== '' && $info[$f[1]] = $f[2],
                'P' => count($f) >= 4 && str_starts_with($f[1], '/') && $paths[$this->clean($f[1])][] = ['category' => $f[2], 'reason' => $f[3]],
                'X' => count($f) >= 3 && $excludes[$f[1]] = $f[2],
                'D' => count($f) >= 4 && $databases[$f[1].'|'.$f[2]] = ['engine' => $f[1], 'where' => $f[2], 'detail' => $f[3]],
                default => null,
            };
        }

        $list = [];
        foreach ($paths as $path => $items) {
            $inside = collect(array_keys($paths))->filter(fn ($p) => $p !== $path && ($p === '/' || str_starts_with($path, $p.'/')))->sortByDesc('strlen')->first();
            $category = $items[0]['category'];
            $list[] = [
                'path' => $path,
                'category' => $category,
                'reasons' => array_values(array_unique(array_column($items, 'reason'))),
                /** Входит в другой найденный каталог */
                'inside' => $inside,
                'recommended' => in_array($category, self::RECOMMENDED, true),
                'size' => null,
            ];
        }
        usort($list, fn ($a, $b) => strcmp($a['path'], $b['path']));
        $list = $this->withSizes($server, $list);

        $existing = DatabaseConnection::query()->where('server_id', $server->id)->get();

        return [
            'info' => $info,
            'paths' => $list,
            'excludes' => collect($excludes)->map(fn ($reason, $pattern) => ['pattern' => (string) $pattern, 'reason' => $reason])->values()->all(),
            'databases' => collect($databases)->map(function ($d) use ($existing) {
                $container = str_starts_with($d['where'], 'docker:') ? substr($d['where'], 7) : null;

                return [
                    'engine' => $d['engine'],
                    'where' => $container ? 'docker' : 'local',
                    'container' => $container,
                    'detail' => $d['detail'],
                    /** SystemKeeper умеет бекапить эту СУБД дампом */
                    'supported' => in_array($d['engine'], DatabaseConnection::ENGINES, true),
                    /** Уже добавлена как база данных этого сервера */
                    'added' => $existing->contains(fn (DatabaseConnection $c) => $c->engine === $d['engine'] && ($c->docker_container ?: null) === $container),
                ];
            })->values()->all(),
        ];
    }

    private function clean(string $path): string
    {
        return rtrim($path, '/') ?: '/';
    }

    /** Размер верхних каталогов (du, не дольше 20 с на каталог); вложенные считаются в родителе. */
    private function withSizes(Server $server, array $list): array
    {
        $top = array_values(array_filter(array_column(array_filter($list, fn ($p) => $p['inside'] === null), 'path')));
        if (! $top) {
            return $list;
        }
        $args = implode(' ', array_map('escapeshellarg', $top));
        $command = "for p in {$args}; do s=\$(timeout 20 du -sxk -- \"\$p\" 2>/dev/null | cut -f1); printf '%s\\t%s\\n' \"\$s\" \"\$p\"; done";

        try {
            $out = $this->ssh->exec($server, $command, 30 * count($top) + 10)['stdout'];
        } catch (RuntimeException) {
            return $list;
        }
        $sizes = [];
        foreach (explode("\n", $out) as $line) {
            [$kb, $path] = array_pad(explode("\t", $line, 2), 2, '');
            if ($path !== '' && ctype_digit($kb)) {
                $sizes[$path] = (int) $kb * 1024;
            }
        }

        return array_map(fn ($p) => [...$p, 'size' => $sizes[$p['path']] ?? null], $list);
    }
}
