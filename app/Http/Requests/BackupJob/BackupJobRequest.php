<?php

namespace App\Http\Requests\BackupJob;

use App\Models\BackupJob;
use Cron\CronExpression;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class BackupJobRequest extends FormRequest
{
    /** Тип задания: при создании из запроса, при обновлении — у существующего (не меняется) */
    abstract protected function jobType(): ?string;

    /** Существующее задание при обновлении */
    protected function existing(): ?BackupJob
    {
        return null;
    }

    public function rules(): array
    {
        $create = $this->existing() === null;
        $required = $create ? ['required'] : ['sometimes', 'required'];
        $type = $this->jobType();

        $rules = [
            'name' => [...$required, 'string', 'max:255'],
            'server_id' => [...$required, 'integer', 'exists:servers,id'],
            'schedule' => ['nullable', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! CronExpression::isValidExpression($value)) {
                    $fail('Некорректное cron-выражение.');
                }
            }],
            'enabled' => ['sometimes', 'boolean'],
            'compression_level' => ['sometimes', 'integer', 'between:1,19'],
            'retention_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'storage_target_ids' => ['nullable', 'array'],
            'storage_target_ids.*' => ['integer', 'min:1'],
        ];

        if ($create) {
            $rules['type'] = ['required', Rule::in(BackupJob::TYPES)];
        }

        if ($type === BackupJob::TYPE_FILES) {
            $rules += [
                'paths' => [...$required, 'array', 'min:1', 'max:100'],
                'paths.*' => ['string', 'max:4096', 'starts_with:/', 'not_regex:/[\x00-\x1f]/'],
                'excludes' => ['nullable', 'array', 'max:200'],
                'excludes.*' => ['string', 'max:1000', 'not_regex:/[\x00-\x1f]/'],
            ];
        } elseif (in_array($type, [BackupJob::TYPE_MYSQL, BackupJob::TYPE_POSTGRES], true)) {
            $rules += [
                'db_mode' => ['sometimes', Rule::in([BackupJob::DB_MODE_SSH, BackupJob::DB_MODE_DIRECT])],
                'db_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:\-\[\]]+$/'],
                'db_port' => ['nullable', 'integer', 'between:1,65535'],
                // Имя БД попадает в аргументы командной строки: без пробелов и ведущего «-»
                'db_name' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_][A-Za-z0-9_$.\-]*$/'],
                'db_user' => ['nullable', 'string', 'max:255', 'regex:/^[^\x00-\x20]+$/'],
                'db_password' => ['nullable', 'string', 'max:1000'],
            ];
        }

        return $rules;
    }

    /**
     * Итоговые (с учётом существующих значений) требования к режиму «напрямую».
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! in_array($this->jobType(), [BackupJob::TYPE_MYSQL, BackupJob::TYPE_POSTGRES], true) || $validator->errors()->isNotEmpty()) {
                return;
            }

            $existing = $this->existing();
            $effective = fn (string $key) => $this->has($key) ? $this->input($key) : $existing?->{$key};
            $mode = $this->has('db_mode') ? $this->input('db_mode') : ($existing?->db_mode ?? BackupJob::DB_MODE_SSH);

            if ($mode === BackupJob::DB_MODE_DIRECT) {
                foreach (['db_host', 'db_user'] as $key) {
                    if (! $effective($key)) {
                        $validator->errors()->add($key, 'Обязательно для режима «напрямую».');
                    }
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'name' => 'название', 'server_id' => 'сервер', 'type' => 'тип', 'schedule' => 'расписание',
            'enabled' => 'включено', 'compression_level' => 'уровень сжатия', 'retention_count' => 'хранить копий',
            'retention_days' => 'хранить дней', 'storage_target_ids' => 'хранилища', 'paths' => 'пути', 'excludes' => 'исключения',
            'db_mode' => 'режим', 'db_host' => 'хост БД', 'db_port' => 'порт БД', 'db_name' => 'база данных',
            'db_user' => 'пользователь БД', 'db_password' => 'пароль БД',
        ];
    }
}
