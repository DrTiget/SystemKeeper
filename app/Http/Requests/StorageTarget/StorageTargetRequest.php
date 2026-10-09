<?php

namespace App\Http\Requests\StorageTarget;

use App\Models\StorageTarget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class StorageTargetRequest extends FormRequest
{
    /** Тип хранилища: при создании из запроса, при обновлении — у существующего (не меняется) */
    abstract protected function targetType(): ?string;

    /** Существующее хранилище при обновлении */
    protected function existing(): ?StorageTarget
    {
        return null;
    }

    public function rules(): array
    {
        $create = $this->existing() === null;
        $required = $create ? ['required'] : ['sometimes', 'required'];
        $path = ['string', 'max:255', 'starts_with:/', 'not_regex:#(^|/)\.\.(/|$)#', 'not_regex:/[\x00-\x1f]/'];

        $rules = [
            'name' => [...$required, 'string', 'max:255', Rule::unique('storage_targets', 'name')->ignore($this->existing()?->id)],
            'enabled' => ['sometimes', 'boolean'],
        ];

        if ($create) {
            $rules['type'] = ['required', Rule::in(StorageTarget::TYPES)];
        }

        switch ($this->targetType()) {
            case StorageTarget::TYPE_LOCAL:
                $rules['base_path'] = [...$required, ...$path];
                break;

            case StorageTarget::TYPE_YANDEX_DISK:
                $rules['base_path'] = [...$required, ...$path];
                $rules['token'] = $create ? ['required', 'string', 'max:4096'] : ['sometimes', 'nullable', 'string', 'max:4096'];
                break;

            case StorageTarget::TYPE_S3:
                $rules += [
                    'endpoint' => ['nullable', 'url:http,https', 'max:255'],
                    'region' => [...$required, 'string', 'max:64', 'regex:/^[A-Za-z0-9\-_.]+$/'],
                    'bucket' => [...$required, 'string', 'min:3', 'max:63', 'regex:/^[A-Za-z0-9][A-Za-z0-9.\-_]*$/'],
                    'prefix' => ['nullable', 'string', 'max:255', 'not_regex:#(^|/)\.\.(/|$)#', 'not_regex:/[\x00-\x1f]/'],
                    'access_key' => [...$required, 'string', 'max:255'],
                    'secret_key' => $create ? ['required', 'string', 'max:1024'] : ['sometimes', 'nullable', 'string', 'max:1024'],
                    'path_style' => ['sometimes', 'boolean'],
                    'storage_class' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z][A-Z0-9_]*$/'],
                ];
                break;

            case StorageTarget::TYPE_SFTP:
                $rules['server_id'] = [...$required, 'integer', 'exists:servers,id'];
                $rules['base_path'] = [...$required, ...$path];
                break;
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'name' => 'название', 'type' => 'тип', 'token' => 'токен', 'base_path' => 'базовая папка',
            'enabled' => 'признак «включено»', 'endpoint' => 'endpoint', 'region' => 'регион', 'bucket' => 'бакет',
            'prefix' => 'префикс', 'access_key' => 'access key', 'secret_key' => 'secret key',
            'path_style' => 'path-style', 'storage_class' => 'класс хранения', 'server_id' => 'сервер',
        ];
    }
}
