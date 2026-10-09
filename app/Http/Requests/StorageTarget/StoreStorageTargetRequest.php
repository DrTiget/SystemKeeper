<?php

namespace App\Http\Requests\StorageTarget;

use App\Models\StorageTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStorageTargetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:storage_targets,name'],
            'type' => ['required', Rule::in(StorageTarget::TYPES)],
            'token' => ['required_if:type,'.StorageTarget::TYPE_YANDEX_DISK, 'nullable', 'string', 'max:4096'],
            'base_path' => ['required', 'string', 'max:255', 'starts_with:/', 'not_regex:#(^|/)\.\.(/|$)#'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'название', 'type' => 'тип', 'token' => 'токен', 'base_path' => 'базовая папка', 'enabled' => 'признак «включено»'];
    }
}
