<?php

namespace App\Services\Ssh;

use App\Exceptions\Ssh\SshConnectionException;
use App\Models\Server;
use InvalidArgumentException;

/**
 * Просмотр каталогов на сервере по SSH — для выбора, что бекапить.
 */
class RemoteFiles
{
    public function __construct(private SshConnectorInterface $ssh) {}

    /**
     * Нормализует абсолютный путь: убирает «.», «..», повторные и конечные слеши.
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(string $path): string
    {
        if (! str_starts_with($path, '/') || preg_match('/[\x00-\x1f]/', $path)) {
            throw new InvalidArgumentException('Нужен абсолютный путь.');
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $part === '..' ? array_pop($parts) : $parts[] = $part;
        }

        return '/'.implode('/', $parts);
    }

    /**
     * Содержимое каталога: сначала каталоги, затем файлы, по имени.
     *
     * @return array{path: string, entries: list<array{name: string, path: string, type: string, link: bool, size: int|null}>, truncated: bool}
     *
     * @throws SshConnectionException
     * @throws RemoteFilesException путь не существует, не каталог или нет доступа
     */
    public function list(Server $server, string $path): array
    {
        $path = self::normalize($path);
        $limit = (int) config('backup.browse_limit');

        // %y — тип (l для ссылки), %Y — тип цели ссылки, %s — размер, %P — имя; записи разделены \0 (имена могут быть любыми)
        $dir = escapeshellarg($path);
        $max = $limit + 1;
        // Запасной вариант без GNU find (busybox): тот же формат через shell и stat
        $fallback = 'n=0; for f in * .[!.]* ..?*; do [ -e "$f" ] || [ -L "$f" ] || continue; '
            .'if [ -d "$f" ]; then Y=d; elif [ -f "$f" ]; then Y=f; else Y=o; fi; if [ -L "$f" ]; then y=l; else y=$Y; fi; '
            .'s=0; [ "$y" = f ] && s=$(stat -c %s -- "$f" 2>/dev/null || echo 0); '
            ."printf '%s\\t%s\\t%s\\t%s\\0' \"\$y\" \"\$Y\" \"\$s\" \"\$f\"; n=\$((n+1)); [ \$n -ge {$max} ] && break; done";
        // head -z нет в старых coreutils (CentOS 7) — там обрезаем по объёму, лишние записи отбросятся ниже
        $head = "if head -z -n 1 </dev/null >/dev/null 2>&1; then head -z -n {$max}; else head -c 8388608; fi";
        $command = "LC_ALL=C; if [ ! -d {$dir} ]; then echo 'not a directory' >&2; exit 3; fi; cd {$dir} || exit 1; "
            ."if find . -maxdepth 0 -printf '' 2>/dev/null; then find . -mindepth 1 -maxdepth 1 -printf '%y\\t%Y\\t%s\\t%P\\0' | {$head}; "
            ."else {$fallback}; fi";

        $result = $this->ssh->exec($server, $command, (int) config('backup.browse_timeout'));

        if ($result['exit_code'] === 3) {
            throw new RemoteFilesException("Каталог {$path} не найден.");
        }
        if ($result['exit_code'] !== 0) {
            $stderr = trim($result['stderr']);
            throw new RemoteFilesException(str_contains($stderr, 'Permission denied')
                ? "Нет доступа к {$path}."
                : "Не удалось прочитать {$path}: ".($stderr ?: 'код '.($result['exit_code'] ?? '—')));
        }

        $lines = explode("\0", $result['stdout']);
        // Вывод обрезан по объёму посреди записи — неполную запись отбрасываем
        $cut = $result['stdout'] !== '' && ! str_ends_with($result['stdout'], "\0");
        if ($cut) {
            array_pop($lines);
        }

        $entries = [];
        foreach ($lines as $line) {
            $fields = explode("\t", $line, 4);
            if (count($fields) !== 4 || $fields[3] === '') {
                continue;
            }
            [$type, $target, $size, $name] = $fields;
            $isLink = $type === 'l';
            $kind = match ($isLink ? $target : $type) {
                'd' => 'dir',
                'f' => 'file',
                default => 'other',
            };
            $entries[] = [
                'name' => $name,
                'path' => rtrim($path, '/').'/'.$name,
                'type' => $kind,
                'link' => $isLink,
                'size' => $kind === 'file' ? (int) $size : null,
            ];
        }

        $truncated = $cut || count($entries) > $limit;
        $entries = array_slice($entries, 0, $limit);
        usort($entries, fn ($a, $b) => [$a['type'] !== 'dir', mb_strtolower($a['name'])] <=> [$b['type'] !== 'dir', mb_strtolower($b['name'])]);

        return ['path' => $path, 'entries' => $entries, 'truncated' => $truncated];
    }
}
