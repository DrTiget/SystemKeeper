<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\Backup\StorageFilesystems;
use App\Services\Storage\YandexDiskClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Куда складываются бекапы: `local` — диск самого SystemKeeper (диск `backups`), `s3` — бакет S3-совместимого
 * хранилища, `yandex_disk` — папка на Яндекс Диске. Настройки шифруются: у S3 — `endpoint`, `region`, `bucket`, `prefix`,
 * `path_style`, `key`, `secret`; у Яндекс Диска — `token` (OAuth) и `folder` (`disk:/SystemKeeper`).
 */
#[Fillable(['name', 'type', 'settings'])]
#[Hidden(['settings'])]
class BackupStorage extends Model
{
    use Auditable;

    public const LOCAL = 'local';

    public const S3 = 's3';

    public const YANDEX_DISK = 'yandex_disk';

    public const TYPES = [self::LOCAL, self::S3, self::YANDEX_DISK];

    protected function casts(): array
    {
        return ['settings' => 'encrypted:array'];
    }

    /** Хранилище для источников, у которых оно не выбрано, — локальное. */
    public static function default(): self
    {
        return self::query()->where('type', self::LOCAL)->orderBy('id')->firstOrFail();
    }

    public function filesystem(): Filesystem
    {
        return app(StorageFilesystems::class)->for($this);
    }

    /** Бекапы, у которых есть удачная копия в этом хранилище */
    public function backups(): BelongsToMany
    {
        return $this->belongsToMany(Backup::class, 'backup_copies', 'storage_id')->wherePivot('status', BackupCopy::SUCCESS);
    }

    public function syncs(): HasMany
    {
        return $this->hasMany(StorageSync::class, 'storage_id');
    }

    /** Успешные бекапы, которых нет в этом хранилище, но есть в другом — их можно скопировать сюда. */
    public function missingBackups(): Builder
    {
        return Backup::query()
            ->where('backups.status', Backup::SUCCESS)
            ->whereDoesntHave('copies', fn ($q) => $q->where('storage_id', $this->id)->where('status', BackupCopy::SUCCESS))
            ->whereHas('copies', fn ($q) => $q->where('storage_id', '!=', $this->id)->where('status', BackupCopy::SUCCESS));
    }

    public function copies(): HasMany
    {
        return $this->hasMany(BackupCopy::class, 'storage_id');
    }

    /**
     * Серверы и базы данных, бекапы которых идут в это хранилище (без выбранного — в хранилище по умолчанию),
     * с занятым объёмом и настройками хранения.
     *
     * @return list<array{type: string, id: int, name: string, project: array<string, mixed>|null, used: int, backups_count: int, keep_count: int, max_size: int|null}>
     */
    public function sources(): array
    {
        $isDefault = $this->is(self::default());
        $mine = fn (?array $ids) => in_array($this->id, $ids ?? [], true) || (! $ids && $isDefault);

        $usage = Backup::query()->whereHas('copies', fn ($q) => $q->where('storage_id', $this->id)->where('status', BackupCopy::SUCCESS))->where('status', Backup::SUCCESS)
            ->selectRaw('source_type, source_id, SUM(size) as used, COUNT(*) as cnt')->groupBy('source_type', 'source_id')
            ->get()->keyBy(fn ($r) => $r->source_type.':'.$r->source_id);

        $row = fn (string $type, Model $m, int $keep, ?int $max, ?array $ids) => [
            'type' => $type,
            'id' => $m->id,
            'name' => $m->name,
            'project' => $m->project ? [
                'id' => $m->project->id,
                'name' => $m->project->name,
                'group' => $m->project->group ? ['id' => $m->project->group->id, 'name' => $m->project->group->name] : null,
            ] : null,
            'used' => (int) ($usage[$type.':'.$m->id]->used ?? 0),
            'backups_count' => (int) ($usage[$type.':'.$m->id]->cnt ?? 0),
            'keep_count' => $keep,
            'max_size' => $max,
            'storage_ids' => $ids ?? [],
        ];

        $rows = [];
        foreach (Server::query()->with(['backupSettings', 'project.group'])->orderBy('name')->get() as $server) {
            $s = $server->backupSettingsOrDefault();
            if ($mine($s->storage_ids) || isset($usage['server:'.$server->id])) {
                $rows[] = $row('server', $server, $s->keep_count, $s->max_size, $s->storage_ids);
            }
        }
        foreach (DatabaseConnection::query()->with('project.group')->orderBy('name')->get() as $db) {
            if ($mine($db->storage_ids) || isset($usage['database:'.$db->id])) {
                $rows[] = $row('database', $db, $db->keep_count, $db->max_size, $db->storage_ids);
            }
        }

        return $rows;
    }

    /**
     * Объём диска, на котором лежат файлы (для локального хранилища).
     *
     * @return array{total: int, free: int}|null
     */
    public function disk(): ?array
    {
        if ($this->type === self::YANDEX_DISK) {
            return $this->yandexQuota();
        }
        if ($this->type !== self::LOCAL) {
            return null;
        }
        $root = Storage::disk('backups')->path('');
        is_dir($root) || @mkdir($root, 0755, true);
        $total = @disk_total_space($root);
        $free = @disk_free_space($root);

        return $total === false || $free === false ? null : ['total' => (int) $total, 'free' => (int) $free];
    }

    /** Объём и свободное место на Яндекс Диске (кешируется на 5 минут); null — Диск недоступен. */
    private function yandexQuota(): ?array
    {
        return Cache::remember("yandex-disk-quota:{$this->id}:".md5((string) ($this->settings['token'] ?? '')), 300, function () {
            try {
                $q = (new YandexDiskClient((string) ($this->settings['token'] ?? '')))->quota();

                return ['total' => $q['total'], 'free' => $q['free']];
            } catch (Throwable) {
                return null;
            }
        });
    }
}
