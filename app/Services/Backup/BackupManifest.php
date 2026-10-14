<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\BackupCopy;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Support\Bytes;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Описание бекапов прямо в хранилище — чтобы восстановиться руками, даже если SystemKeeper недоступен.
 *
 * - `{каталог бекапа}/backup.json` — что это за бекап, файлы с SHA-256 и как восстановить. Пишется один раз.
 * - `index.json` в корне — оглавление: какой каталог (`servers/5`) какому серверу или базе принадлежит и какие в нём бекапы.
 * - `README.md` в корне — то же для человека.
 *
 * Оглавление могут править люди или другая копия SystemKeeper, поэтому перед каждым обновлением файлы перечитываются:
 * меняется только запись своего источника (и в ней — только свои поля), чужие записи и поля сохраняются;
 * в README заменяется только блок между служебными метками, остальной текст остаётся.
 */
class BackupManifest
{
    public const INDEX = 'index.json';

    public const README = 'README.md';

    public const BACKUP_INFO = 'backup.json';

    private const BEGIN = '<!-- systemkeeper:begin — этот блок обновляет SystemKeeper, свои заметки пишите вне его -->';

    private const END = '<!-- systemkeeper:end -->';

    /** Описание одного бекапа в его каталоге. */
    public function writeBackupInfo(Filesystem $fs, Backup $backup): void
    {
        $fs->put($backup->path.'/'.self::BACKUP_INFO, self::json([
            'systemkeeper_backup' => 1,
            'id' => $backup->id,
            'created_at' => ($backup->started_at ?? $backup->created_at)?->toIso8601String(),
            'source' => $this->describeSource($backup->source),
            'files' => $backup->files ?? [],
            'meta' => $backup->meta ?? (object) [],
            'restore' => $this->restoreHints($backup),
        ]));
    }

    /**
     * Обновляет оглавление хранилища для источника: его удачные бекапы, лежащие в этом хранилище.
     * Ошибки не пробрасываются — бекап важнее оглавления; возвращается текст ошибки или null.
     */
    public function sync(BackupStorage $storage, Server|DatabaseConnection $source): ?string
    {
        try {
            // два бекапа могут закончиться одновременно — обновляем оглавление по очереди
            return Cache::lock("backup-manifest:{$storage->id}", 120)->block(60, fn () => $this->doSync($storage, $source));
        } catch (Throwable $e) {
            Log::warning('Не удалось обновить оглавление хранилища', ['storage' => $storage->id, 'error' => $e->getMessage()]);

            return "Не удалось обновить оглавление в «{$storage->name}»: ".mb_substr($e->getMessage(), 0, 500);
        }
    }

    private function doSync(BackupStorage $storage, Server|DatabaseConnection $source): ?string
    {
        $fs = $storage->filesystem();
        $index = $this->readIndex($fs);
        $key = $this->key($source);

        $backups = $source->backups()->where('status', Backup::SUCCESS)
            ->whereHas('copies', fn ($q) => $q->where('storage_id', $storage->id)->where('status', BackupCopy::SUCCESS))
            ->orderByDesc('id')->get();

        if ($backups->isEmpty()) {
            unset($index['sources'][$key]);
        } else {
            $own = [
                ...$this->describeSource($source),
                'directory' => $key,
                'updated_at' => now()->toIso8601String(),
                'backups' => $backups->map(fn (Backup $b) => [
                    'directory' => $b->path,
                    'created_at' => ($b->started_at ?? $b->created_at)?->toIso8601String(),
                    'size' => $b->size,
                    'files' => array_map(fn ($f) => array_intersect_key($f, array_flip(['name', 'size', 'sha256'])), $b->files ?? []),
                ])->all(),
            ];
            // чужие поля записи (заметки и т. п.) сохраняются
            $index['sources'][$key] = [...(is_array($index['sources'][$key] ?? null) ? $index['sources'][$key] : []), ...$own];
        }
        ksort($index['sources']);
        $index['updated_at'] = now()->toIso8601String();

        $fs->put(self::INDEX, self::json($index));
        $fs->put(self::README, $this->mergeReadme($fs->exists(self::README) ? (string) $fs->get(self::README) : null, $index));

        return null;

    }

    /** Ключ источника в оглавлении — он же каталог с его бекапами. */
    public function key(Server|DatabaseConnection $source): string
    {
        return ($source instanceof Server ? 'servers' : 'databases').'/'.$source->id;
    }

    /**
     * Текущее оглавление. Испорченный руками JSON не затираем молча: откладываем копию рядом и начинаем заново.
     *
     * @return array<string, mixed>
     */
    private function readIndex(Filesystem $fs): array
    {
        $fresh = ['systemkeeper_index' => 1, 'about' => 'Оглавление бекапов SystemKeeper: каталог → сервер или база данных. Описание каждого бекапа — в его каталоге, файл backup.json.', 'sources' => []];
        if (! $fs->exists(self::INDEX)) {
            return $fresh;
        }
        $raw = (string) $fs->get(self::INDEX);
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            $fs->put(self::INDEX.'.broken-'.now()->format('Ymd-His'), $raw);

            return $fresh;
        }
        $data['sources'] = is_array($data['sources'] ?? null) ? $data['sources'] : [];

        return $data;
    }

    /** README: служебный блок заменяется (или дописывается), остальной текст не трогаем. */
    private function mergeReadme(?string $current, array $index): string
    {
        $block = self::BEGIN."\n".$this->renderReadme($index).self::END;
        if ($current === null || trim($current) === '') {
            return "# Бекапы SystemKeeper\n\n".$block."\n";
        }
        $start = strpos($current, self::BEGIN);
        $end = strpos($current, self::END);
        if ($start !== false && $end !== false && $end > $start) {
            return substr($current, 0, $start).$block.substr($current, $end + strlen(self::END));
        }

        return rtrim($current)."\n\n".$block."\n";
    }

    private function renderReadme(array $index): string
    {
        $md = 'Обновлено: '.now()->setTimezone(config('backup.timezone'))->format('d.m.Y H:i')." ({$this->tz()}).\n\n"
            ."Каждый каталог ниже — один сервер или база данных; внутри — каталоги бекапов `ГГГГ-ММ-ДД_ЧЧММСС-id`.\n"
            ."В каждом каталоге бекапа есть `backup.json`: файлы с SHA-256 (проверка: `sha256sum файл`) и команды восстановления.\n"
            ."Машиночитаемое оглавление — `index.json`.\n\n";

        foreach ($index['sources'] as $key => $s) {
            if (! is_array($s)) {
                continue;
            }
            $title = ($s['type'] ?? '') === 'server' ? 'Сервер' : 'База данных';
            $project = isset($s['project']['name']) ? ' · проект «'.$s['project']['name'].'»'.(isset($s['project']['group']) ? ' (группа «'.$s['project']['group'].'»)' : '') : '';
            $md .= "## `{$key}/` — {$title} «".($s['name'] ?? '?')."»{$project}\n\n";
            if (($s['type'] ?? '') === 'server') {
                $md .= '- Адрес: `'.($s['address'] ?? '—')."`\n";
                $md .= '- Каталоги: '.implode(', ', array_map(fn ($p) => "`{$p}`", $s['paths'] ?? []))."\n";
                if (! empty($s['excludes'])) {
                    $md .= '- Исключения: '.implode(', ', array_map(fn ($p) => "`{$p}`", $s['excludes']))."\n";
                }
                $md .= "- Восстановление: `tar -xzf files.tar.gz -C /` (пути в архиве — от корня, без ведущего `/`; можно распаковать в любой каталог)\n";
            } else {
                $md .= '- СУБД: '.(($s['engine'] ?? '') === 'postgres' ? 'PostgreSQL' : 'MySQL / MariaDB').', `'.($s['address'] ?? '—')."`\n";
                $md .= ($s['engine'] ?? '') === 'postgres'
                    ? "- Восстановление: `<база>.dump` — `pg_restore -d <база> <база>.dump`; `<база>.sql.gz` — `gunzip -c <база>.sql.gz | psql <база>`\n"
                    : "- Восстановление: `gunzip -c <база>.sql.gz | mysql` (дамп сам создаёт базу); без `.gz` — `mysql < <база>.sql`\n";
            }
            $md .= "\n| Бекап | Дата | Размер | Файлы |\n|---|---|---|---|\n";
            foreach ($s['backups'] ?? [] as $b) {
                $dir = basename((string) ($b['directory'] ?? ''));
                $date = isset($b['created_at']) ? Carbon::parse($b['created_at'])->setTimezone(config('backup.timezone'))->format('d.m.Y H:i') : '—';
                $size = isset($b['size']) ? Bytes::format((int) $b['size']) : '—';
                $files = implode(', ', array_map(fn ($f) => '`'.($f['name'] ?? '?').'`', $b['files'] ?? []));
                $md .= "| `{$dir}` | {$date} | {$size} | {$files} |\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSource(Server|DatabaseConnection|null $source): array
    {
        if ($source === null) {
            return ['type' => null];
        }
        $project = $source->project ? [
            'id' => $source->project->id,
            'name' => $source->project->name,
            ...($source->project->group ? ['group' => $source->project->group->name] : []),
        ] : null;

        if ($source instanceof Server) {
            $settings = $source->backupSettingsOrDefault();

            return [
                'type' => 'server', 'id' => $source->id, 'name' => $source->name, 'project' => $project,
                'address' => "{$source->username}@{$source->host}:{$source->port}",
                'paths' => $settings->paths, 'excludes' => $settings->excludes ?? [],
            ];
        }

        return [
            'type' => 'database', 'id' => $source->id, 'name' => $source->name, 'project' => $project,
            'engine' => $source->engine, 'address' => "{$source->username}@{$source->host}:{$source->port}",
            'via' => $source->via === DatabaseConnection::VIA_SSH ? 'ssh: '.$source->server?->name.($source->docker_container ? ", docker {$source->docker_container}" : '') : 'direct',
        ];
    }

    /**
     * @return list<string>
     */
    private function restoreHints(Backup $backup): array
    {
        $hints = ['Проверить целостность: sha256sum <файл> и сравнить с files[].sha256'];
        foreach ($backup->files ?? [] as $f) {
            $name = $f['name'];
            $hints[] = match (true) {
                $name === 'files.tar.gz' => "{$name}: tar -xzf {$name} -C / (или -C <каталог>, чтобы распаковать в сторону)",
                str_ends_with($name, '.dump') => "{$name}: pg_restore -d <база> {$name}",
                str_ends_with($name, '.sql.gz') => $backup->source instanceof DatabaseConnection && $backup->source->engine === DatabaseConnection::POSTGRES
                    ? "{$name}: gunzip -c {$name} | psql <база>" : "{$name}: gunzip -c {$name} | mysql",
                str_ends_with($name, '.sql') => "{$name}: mysql < {$name} (PostgreSQL: psql <база> < {$name})",
                default => $name,
            };
        }

        return $hints;
    }

    private function tz(): string
    {
        return (string) config('backup.timezone');
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }
}
