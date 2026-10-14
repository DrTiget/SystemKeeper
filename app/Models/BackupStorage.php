<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Куда складываются бекапы. Пока есть один тип — `local`, диск самого SystemKeeper (диск `backups`).
 */
#[Fillable(['name', 'type', 'settings'])]
class BackupStorage extends Model
{
    public const LOCAL = 'local';

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** Хранилище для источников, у которых оно не выбрано, — локальное. */
    public static function default(): self
    {
        return self::query()->where('type', self::LOCAL)->orderBy('id')->firstOrFail();
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class, 'storage_id');
    }

    /**
     * Серверы и базы данных, бекапы которых идут в это хранилище (без выбранного — в хранилище по умолчанию),
     * с занятым объёмом и настройками хранения.
     *
     * @return list<array{type: string, id: int, name: string, used: int, backups_count: int, keep_count: int, max_size: int|null}>
     */
    public function sources(): array
    {
        $isDefault = $this->is(self::default());
        $mine = fn (?int $storageId) => $storageId === $this->id || ($storageId === null && $isDefault);

        $usage = Backup::query()->where('storage_id', $this->id)->where('status', Backup::SUCCESS)
            ->selectRaw('source_type, source_id, SUM(size) as used, COUNT(*) as cnt')->groupBy('source_type', 'source_id')
            ->get()->keyBy(fn ($r) => $r->source_type.':'.$r->source_id);

        $row = fn (string $type, Model $m, int $keep, ?int $max) => [
            'type' => $type,
            'id' => $m->id,
            'name' => $m->name,
            'used' => (int) ($usage[$type.':'.$m->id]->used ?? 0),
            'backups_count' => (int) ($usage[$type.':'.$m->id]->cnt ?? 0),
            'keep_count' => $keep,
            'max_size' => $max,
        ];

        $rows = [];
        foreach (Server::query()->with('backupSettings')->orderBy('name')->get() as $server) {
            $s = $server->backupSettingsOrDefault();
            if ($mine($s->storage_id) || isset($usage['server:'.$server->id])) {
                $rows[] = $row('server', $server, $s->keep_count, $s->max_size);
            }
        }
        foreach (DatabaseConnection::query()->orderBy('name')->get() as $db) {
            if ($mine($db->storage_id) || isset($usage['database:'.$db->id])) {
                $rows[] = $row('database', $db, $db->keep_count, $db->max_size);
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
        if ($this->type !== self::LOCAL) {
            return null;
        }
        $root = Storage::disk('backups')->path('');
        is_dir($root) || @mkdir($root, 0755, true);
        $total = @disk_total_space($root);
        $free = @disk_free_space($root);

        return $total === false || $free === false ? null : ['total' => (int) $total, 'free' => (int) $free];
    }
}
