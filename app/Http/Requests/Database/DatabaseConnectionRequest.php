<?php

namespace App\Http\Requests\Database;

use App\Models\DatabaseConnection;
use Cron\CronExpression;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Создание (POST) и изменение (PATCH/PUT) подключения к БД вместе с настройками бекапа.
 * При изменении пароль можно не передавать — останется прежний.
 */
class DatabaseConnectionRequest extends FormRequest
{
    private function creating(): bool
    {
        return $this->isMethod('post');
    }

    private function target(): ?DatabaseConnection
    {
        return $this->route('database');
    }

    /** Итоговое значение поля с учётом сохранённого. */
    private function final(string $key): mixed
    {
        return $this->has($key) ? $this->input($key) : $this->target()?->{$key};
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->creating() ? 'required' : 'sometimes';
        $engine = $this->final('engine');
        $optionKeys = array_keys(DatabaseConnection::DEFAULT_OPTIONS[$engine] ?? []);
        $table = ['required', 'string', 'max:255', 'distinct', 'regex:/^[^.\s]+\.[^\s]+$/'];

        return [
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'engine' => [$required, Rule::in(DatabaseConnection::ENGINES)],
            'via' => [$required, Rule::in([DatabaseConnection::VIA_SSH, DatabaseConnection::VIA_DIRECT])],
            'server_id' => [Rule::requiredIf($this->final('via') === DatabaseConnection::VIA_SSH && ! $this->target()?->server_id), 'nullable', 'integer', Rule::exists('servers', 'id')],
            'docker_container' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/'],
            'host' => [$required, 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]_]+$/'],
            'port' => ['sometimes', 'integer', 'between:1,65535'],
            'username' => [$required, 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:1000'],
            'maintenance_database' => ['nullable', 'string', 'max:255'],

            'backup_enabled' => ['sometimes', 'boolean'],
            'schedule' => ['sometimes', 'string', 'max:100'],
            // Пустой список — все базы, кроме служебных
            'databases' => ['sometimes', 'array', 'max:500'],
            'databases.*' => ['required', 'string', 'max:255', 'distinct'],
            // Таблицы в формате «база.таблица» (для PostgreSQL — «база.схема.таблица»)
            'exclude_tables' => ['sometimes', 'array', 'max:2000'],
            'exclude_tables.*' => $table,
            'schema_only_tables' => ['sometimes', 'array', 'max:2000'],
            'schema_only_tables.*' => $table,
            'options' => ['sometimes', $optionKeys ? 'array:'.implode(',', $optionKeys) : 'array'],
            'options.lock_mode' => ['sometimes', Rule::in(['single_transaction', 'lock_tables', 'none'])],
            'options.format' => ['sometimes', Rule::in(['custom', 'plain'])],
            'options.routines' => ['sometimes', 'boolean'],
            'options.events' => ['sometimes', 'boolean'],
            'options.triggers' => ['sometimes', 'boolean'],
            'options.no_owner' => ['sometimes', 'boolean'],
            'options.compress' => ['sometimes', 'boolean'],

            'storage_id' => ['sometimes', 'nullable', 'integer', Rule::exists('backup_storages', 'id')],
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
            if ($this->has('schedule') && ! CronExpression::isValidExpression((string) $this->input('schedule'))) {
                $validator->errors()->add('schedule', 'Неверное расписание: нужно cron-выражение из 5 полей, например «0 3 * * *».');
            }
            if ($this->filled('docker_container') && $this->final('via') !== DatabaseConnection::VIA_SSH) {
                $validator->errors()->add('docker_container', 'Docker-контейнер можно указать только при подключении через сервер.');
            }
        }];
    }

    /**
     * Данные для сохранения: порт по умолчанию, лишнее для способа подключения очищается,
     * параметры дампа сливаются с текущими.
     *
     * @return array<string, mixed>
     */
    public function connectionData(): array
    {
        $data = $this->safe()->except(['password']);
        $engine = $this->final('engine');

        if ($this->filled('password')) {
            $data['password'] = $this->input('password');
        } elseif ($this->has('password') && $this->input('password') === null && $this->creating()) {
            $data['password'] = null;
        }

        if ($this->creating() && ! isset($data['port'])) {
            $data['port'] = DatabaseConnection::DEFAULT_PORTS[$engine];
        }
        if ($this->creating()) {
            $data['schedule'] ??= config('backup.default_schedule');
        }
        if ($this->final('via') === DatabaseConnection::VIA_DIRECT) {
            $data['server_id'] = null;
            $data['docker_container'] = null;
        }
        if (array_key_exists('docker_container', $data)) {
            $data['docker_container'] = $data['docker_container'] ?: null;
        }
        // Смена СУБД сбрасывает параметры дампа — у MySQL и PostgreSQL они разные
        $base = $this->target() && $this->target()->engine === $engine ? $this->target()->resolvedOptions() : DatabaseConnection::DEFAULT_OPTIONS[$engine];
        $data['options'] = array_merge($base, $data['options'] ?? []);

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'project_id' => 'проект',
            'name' => 'название',
            'engine' => 'СУБД',
            'via' => 'способ подключения',
            'server_id' => 'сервер',
            'docker_container' => 'Docker-контейнер',
            'host' => 'хост',
            'port' => 'порт',
            'username' => 'пользователь',
            'password' => 'пароль',
            'maintenance_database' => 'база для подключения',
            'backup_enabled' => 'бекап включён',
            'schedule' => 'расписание',
            'databases' => 'базы',
            'databases.*' => 'база',
            'storage_id' => 'хранилище',
            'keep_count' => 'сколько бекапов хранить',
            'max_size' => 'лимит объёма',
            'exclude_tables.*' => 'таблица',
            'schema_only_tables.*' => 'таблица',
            'options' => 'параметры дампа',
            'options.lock_mode' => 'блокировка',
            'options.format' => 'формат',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'host.regex' => 'Укажите IP-адрес или доменное имя.',
            'docker_container.regex' => 'Укажите имя или ID контейнера.',
            'exclude_tables.*.regex' => 'Таблица указывается как «база.таблица».',
            'schema_only_tables.*.regex' => 'Таблица указывается как «база.таблица».',
        ];
    }
}
