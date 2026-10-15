<?php

namespace App\Http\Requests\Storage;

use App\Models\BackupStorage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Создание и изменение хранилища. Создать можно S3 и Яндекс Диск (локальное — одно, встроенное);
 * секретный ключ S3 и токен Диска при изменении можно не передавать — останутся прежние.
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
        // при создании поля проверяются по выбранному типу; неизвестный тип — как S3 (сам тип отклонит правило type)
        $type = $creating ? ($this->input('type') === BackupStorage::YANDEX_DISK ? BackupStorage::YANDEX_DISK : BackupStorage::S3) : $storage->type;
        $s3 = $type === BackupStorage::S3;
        $yandex = $type === BackupStorage::YANDEX_DISK;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$creating ? 'required' : 'prohibited', Rule::in([BackupStorage::S3, BackupStorage::YANDEX_DISK])],
            // Яндекс Диск: OAuth-токен с правами на Диск и папка (`disk:/…` или `app:/…` для доступа только к папке приложения)
            'token' => $yandex ? [$creating ? 'required' : 'sometimes', 'string', 'max:1000'] : ['prohibited'],
            'folder' => $yandex ? ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^(disk:|app:)?/?[^\\\\:*?"<>|]*$#u'] : ['prohibited'],
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

        $type = $this->route('storage')?->type ?? ($v['type'] ?? BackupStorage::S3);
        $keys = $type === BackupStorage::YANDEX_DISK ? ['token', 'folder'] : ['endpoint', 'region', 'bucket', 'prefix', 'path_style', 'key', 'secret'];
        if (array_intersect(array_keys($v), $keys) || $this->route('storage') === null) {
            $settings = [...($this->route('storage')?->settings ?? []), ...array_intersect_key($v, array_flip($keys))];
            if ($type === BackupStorage::YANDEX_DISK) {
                $settings['folder'] = self::folder((string) ($settings['folder'] ?? ''));
            } else {
                $settings['prefix'] = trim((string) ($settings['prefix'] ?? ''), '/');
                $settings['path_style'] = (bool) ($settings['path_style'] ?? false);
            }
            $data['settings'] = $settings;
        }

        return $data;
    }

    /** Папка на Диске в полном виде: `SystemKeeper` → `disk:/SystemKeeper`, пусто — `disk:/SystemKeeper`. */
    public static function folder(string $folder): string
    {
        $folder = trim($folder);
        if ($folder === '' || $folder === 'disk:' || $folder === 'disk:/') {
            return 'disk:/SystemKeeper';
        }
        if (preg_match('#^(disk|app):/?(.*)$#', $folder, $m)) {
            $rest = trim($m[2], '/');

            return "{$m[1]}:/".$rest;
        }

        return 'disk:/'.trim($folder, '/');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'название', 'type' => 'тип', 'endpoint' => 'адрес', 'region' => 'регион', 'bucket' => 'бакет',
            'prefix' => 'каталог', 'path_style' => 'path-style', 'key' => 'ключ доступа', 'secret' => 'секретный ключ',
            'token' => 'токен', 'folder' => 'папка',
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
            'folder.regex' => 'Папка: путь на Диске, например SystemKeeper или disk:/Бекапы/Серверы (без символов \\ : * ? " < > |).',
            '*.prohibited' => 'Для этого хранилища поле не задаётся.',
        ];
    }
}
