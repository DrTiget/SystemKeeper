<?php

namespace App\Models\Concerns;

use App\Services\Audit\Audit;

/**
 * Изменения модели пользователем или приложением через API пишутся в журнал действий.
 * Поля из `$auditIgnore` модели в изменения не попадают.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::registerModelEvent($event, fn ($model) => app(Audit::class)->recordModel($event, $model));
        }
    }
}
