<?php

namespace App\Http\Requests\Storage;

use App\Models\BackupStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Создание и изменение хранилища. Создать можно только S3 (локальное — одно, встроенное);
 * секретный ключ при изменении можно не передавать — останется прежний.
 */
class BackupStorageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $storage = $this->route('storage');
        $creating = $storage === null;
        $s3 = $creating || $storage->type === BackupStorage::S3;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$creating ? 'required' : 'prohibited', Rule::in([BackupStorage::S3])],
            // Пусто — Amazon S3; для Яндекса, Selectel, MinIO и т. п. — их адрес
            'endpoint' => $s3 ? ['sometimes', 'nullable', 'url:http,https', 'max:255'] : ['prohibited'],
            'region' => $s3 ? ['sometimes', 'nullable', 'string', 'max:64'] : ['prohibited'],
            'bucket' => $s3 ? [$required, 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/'] : ['prohibited'],
            // Каталог внутри бакета
            'prefix' => $s3 ? ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.\-/]*$#'] : ['prohibited'],
            'path_style' => $s3 ? ['sometimes', 'boolean'] : ['prohibited'],
            'key' => $s3 ? [$required, 'string', 'max:255'] : ['prohibited'],
            'secret' => $s3 ? [$creating ? 'required' : 'sometimes', 'string', 'max:255'] : ['prohibited'],
        ];
    }

    /**
     * @return array{name?: string, type?: string, settings?: array<string, mixed>}
     */
    public function storageData(): array
    {
        $v = $this->validated();
        $data = array_intersect_key($v, array_flip(['name', 'type']));

        $keys = ['endpoint', 'region', 'bucket', 'prefix', 'path_style', 'key', 'secret'];
        if (array_intersect(array_keys($v), $keys)) {
            $settings = [...($this->route('storage')?->settings ?? []), ...array_intersect_key($v, array_flip($keys))];
            $settings['prefix'] = trim((string) ($settings['prefix'] ?? ''), '/');
            $settings['path_style'] = (bool) ($settings['path_style'] ?? false);
            $data['settings'] = $settings;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'название', 'type' => 'тип', 'endpoint' => 'адрес', 'region' => 'регион', 'bucket' => 'бакет',
            'prefix' => 'каталог', 'path_style' => 'path-style', 'key' => 'ключ доступа', 'secret' => 'секретный ключ',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bucket.regex' => 'Имя бакета: строчные латинские буквы, цифры, точки и дефисы, от 3 до 63 символов.',
            'prefix.regex' => 'Каталог: латинские буквы, цифры, «_», «-», «.» и «/».',
            '*.prohibited' => 'Для этого хранилища поле не задаётся.',
        ];
    }
}
