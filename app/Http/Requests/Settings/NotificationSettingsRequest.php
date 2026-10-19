<?php

namespace App\Http\Requests\Settings;

use App\Services\Settings\NotificationSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Сохранение настроек уведомлений (NotificationSettingsInput). Разделы и поля можно слать частично: что не передано,
 * не меняется. Пустой или отсутствующий пароль оставляет прежний, стереть — `clear_password: true`.
 */
class NotificationSettingsRequest extends FormRequest
{
    private const HOST = 'regex:/^[A-Za-z0-9._\-:\[\]]+$/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mail' => ['sometimes', 'array'],
            'mail.host' => ['sometimes', 'nullable', 'string', 'max:255', self::HOST],
            'mail.port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'mail.encryption' => ['sometimes', 'nullable', Rule::in(['none', 'tls', 'ssl'])],
            'mail.username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail.password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail.clear_password' => ['sometimes', 'boolean'],
            'mail.from_address' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'mail.from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'telegram' => ['sometimes', 'array'],
            'telegram.api_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'telegram.proxy' => ['sometimes', 'array'],
            'telegram.proxy.enabled' => ['sometimes', 'boolean'],
            'telegram.proxy.type' => ['sometimes', 'nullable', Rule::in(['http', 'socks5'])],
            'telegram.proxy.host' => ['sometimes', 'nullable', 'string', 'max:255', self::HOST],
            'telegram.proxy.port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'telegram.proxy.username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'telegram.proxy.password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'telegram.proxy.clear_password' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'mail.host' => 'сервер SMTP',
            'mail.port' => 'порт SMTP',
            'mail.encryption' => 'шифрование',
            'mail.username' => 'логин SMTP',
            'mail.password' => 'пароль SMTP',
            'mail.from_address' => 'адрес отправителя',
            'mail.from_name' => 'имя отправителя',
            'telegram.api_url' => 'адрес Bot API',
            'telegram.proxy.type' => 'тип прокси',
            'telegram.proxy.host' => 'хост прокси',
            'telegram.proxy.port' => 'порт прокси',
            'telegram.proxy.username' => 'логин прокси',
            'telegram.proxy.password' => 'пароль прокси',
        ];
    }

    /** Требования к итоговым настройкам (после наложения): SMTP — адрес отправителя, прокси — хост и порт. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $merged = app(NotificationSettings::class)->merge($this->validated());

            if (filled($merged['mail']['host']) && blank($merged['mail']['from_address'])) {
                $validator->errors()->add('mail.from_address', 'Укажите адрес отправителя.');
            }
            $proxy = $merged['telegram']['proxy'];
            if ($proxy['enabled']) {
                blank($proxy['host']) && $validator->errors()->add('telegram.proxy.host', 'Укажите хост прокси.');
                blank($proxy['port']) && $validator->errors()->add('telegram.proxy.port', 'Укажите порт прокси.');
            }
        }];
    }

    /** Итоговые настройки для сохранения. */
    public function merged(): array
    {
        return app(NotificationSettings::class)->merge($this->validated());
    }
}
