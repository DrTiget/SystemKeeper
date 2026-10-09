<?php

namespace App\Http\Requests\StorageTarget;

use App\Models\StorageTarget;

class StoreStorageTargetRequest extends StorageTargetRequest
{
    protected function targetType(): ?string
    {
        $type = $this->input('type');

        return in_array($type, StorageTarget::TYPES, true) ? $type : null;
    }
}
