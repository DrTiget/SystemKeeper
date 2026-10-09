<?php

namespace App\Http\Requests\StorageTarget;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Тип хранилища не меняется. Токен необязателен: без него остаётся прежний.
 */
class UpdateStorageTargetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('storage_targets', 'name')->ignore($this->route('storage_target'))],
            'token' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'base_path' => ['sometimes', 'required', 'string', 'max:255', 'starts_with:/', 'not_regex:#(^|/)\.\.(/|$)#'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'название', 'token' => 'токен', 'base_path' => 'базовая папка', 'enabled' => 'признак «включено»'];
    }
}
