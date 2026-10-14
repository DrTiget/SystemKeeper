<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\DatabaseConnection;
use App\Models\Server;
use App\Support\Bytes;

/**
 * Очистка старых бекапов источника после нового: оставляем `keep_count` последних успешных
 * и укладываемся в `max_size`, вытесняя самые старые. Самый новый бекап не удаляется никогда.
 */
class BackupRetention
{
    /** Сколько записей о неудачных бекапах оставлять в истории */
    public const KEEP_FAILED = 20;

    /**
     * @return list<string> что удалено — для журнала бекапа
     */
    public function apply(Server|DatabaseConnection $source): array
    {
        $retention = $source->retention();
        $log = [];

        $successful = $source->backups()->where('status', Backup::SUCCESS)->orderByDesc('id')->get();
        foreach ($successful->slice(max(1, $retention->keepCount)) as $old) {
            $log[] = "Удалён старый бекап от {$this->date($old)}: хранится {$retention->keepCount} последних";
            $old->delete();
        }
        $successful = $successful->take(max(1, $retention->keepCount))->values();

        if ($retention->maxSize !== null) {
            $total = $successful->sum('size');
            while ($total > $retention->maxSize && $successful->count() > 1) {
                $old = $successful->pop();
                $total -= $old->size;
                $log[] = "Удалён бекап от {$this->date($old)}: превышен лимит ".Bytes::format($retention->maxSize);
                $old->delete();
            }
            if ($total > $retention->maxSize) {
                $log[] = 'Последний бекап ('.Bytes::format($total).') больше лимита '.Bytes::format($retention->maxSize).' — сохранён, но лимит стоит увеличить';
            }
        }

        $source->backups()->where('status', Backup::FAILED)->orderByDesc('id')->skip(self::KEEP_FAILED)->take(PHP_INT_MAX)->get()->each->delete();

        return $log;
    }

    private function date(Backup $b): string
    {
        return ($b->started_at ?? $b->created_at)->setTimezone(config('backup.timezone'))->format('d.m.Y H:i');
    }
}
