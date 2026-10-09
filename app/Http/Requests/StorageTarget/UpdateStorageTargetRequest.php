<?php

namespace App\Http\Requests\StorageTarget;

use App\Models\StorageTarget;

/**
 * Тип хранилища не меняется. Секреты (токен, secret key) необязательны: без них остаются прежние.
 */
class UpdateStorageTargetRequest extends StorageTargetRequest
{
    protected function targetType(): ?string
    {
        return $this->existing()?->type;
    }

    protected function existing(): ?StorageTarget
    {
        $target = $this->route('storage_target');

        return $target instanceof StorageTarget ? $target : StorageTarget::find($target);
    }
}
