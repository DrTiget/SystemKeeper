<?php

namespace App\Events;

use App\Models\StorageCopy;
use Illuminate\Foundation\Events\Dispatchable;

/** Не удалось загрузить копию файла в хранилище. */
class StorageCopyFailed
{
    use Dispatchable;

    public function __construct(public readonly StorageCopy $copy) {}
}
