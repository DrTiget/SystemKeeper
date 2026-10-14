<?php

namespace App\Http\Requests\Server;

use Cron\CronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Настройки бекапа сервера. Передаются частично; включить бекап можно только с хотя бы одним каталогом.
 */
class UpdateBackupSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'paths' => ['sometimes', 'array', 'max:50'],
            'paths.*' => ['required', 'string', 'max:1024', 'distinct', 'regex:#^/#', 'not_regex:/[\x00-\x1f]/'],
            'excludes' => ['sometimes', 'array', 'max:100'],
            'excludes.*' => ['required', 'string', 'max:1024', 'distinct', 'not_regex:/[\x00-\x1f]/'],
            'schedule' => ['sometimes', 'string', 'max:100'],
            'pre_command' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'pre_command_timeout' => ['sometimes', 'integer', 'between:10,86400'],
            'pre_command_continue_on_error' => ['sometimes', 'boolean'],
            // Куда сохранять, можно несколько; пусто — в хранилище по умолчанию
            'storage_ids' => ['sometimes', 'array', 'max:20'],
            'storage_ids.*' => ['integer', 'distinct', Rule::exists('backup_storages', 'id')],
            // Сколько последних успешных бекапов хранить
            'keep_count' => ['sometimes', 'integer', 'between:1,1000'],
            // Предельный объём всех бекапов, байт (не меньше 1 МБ); null — без ограничения
            'max_size' => ['sometimes', 'nullable', 'integer', 'min:1048576'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->has('schedule') && ! $validator->errors()->has('schedule') && ! CronExpression::isValidExpression((string) $this->input('schedule'))) {
                $validator->errors()->add('schedule', 'Неверное расписание: нужно cron-выражение из 5 полей, например «0 3 * * *».');
            }

            $settings = $this->route('server')->backupSettingsOrDefault();
            $enabled = $this->has('enabled') ? $this->boolean('enabled') : $settings->enabled;
            $paths = $this->has('paths') ? (array) $this->input('paths') : $settings->paths;
            if ($enabled && ! $validator->errors()->has('paths') && $paths === []) {
                $validator->errors()->add('paths', 'Чтобы включить бекап, укажите хотя бы один каталог.');
            }
        }];
    }

    /**
     * Пустую команду храним как null.
     *
     * @return array<string, mixed>
     */
    public function settingsData(): array
    {
        $data = $this->validated();
        if (array_key_exists('pre_command', $data)) {
            $data['pre_command'] = trim((string) $data['pre_command']) ?: null;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'enabled' => 'бекап включён',
            'paths' => 'каталоги',
            'paths.*' => 'каталог',
            'excludes' => 'исключения',
            'excludes.*' => 'исключение',
            'storage_ids' => 'куда сохранять',
            'storage_ids.*' => 'место хранения',
            'keep_count' => 'сколько бекапов хранить',
            'max_size' => 'лимит объёма',
            'schedule' => 'расписание',
            'pre_command' => 'команда перед бекапом',
            'pre_command_timeout' => 'таймаут команды',
            'pre_command_continue_on_error' => 'продолжать при ошибке команды',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paths.*.regex' => 'Каталог должен быть абсолютным путём (начинаться с /).',
            'paths.*.distinct' => 'Каталог указан дважды.',
            'excludes.*.distinct' => 'Исключение указано дважды.',
        ];
    }
}
