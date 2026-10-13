<?php

namespace App\Http\Requests\Server;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

/**
 * Создание (POST) и изменение (PATCH/PUT) сервера.
 * При изменении учётные данные можно не передавать — останутся прежние; при смене auth_type нужны новые.
 */
class ServerRequest extends FormRequest
{
    private function creating(): bool
    {
        return $this->isMethod('post');
    }

    private function target(): ?Server
    {
        return $this->route('server');
    }

    /** Итоговый способ авторизации с учётом текущего значения сервера. */
    public function authType(): ?string
    {
        return $this->input('auth_type', $this->target()?->auth_type);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->creating() ? 'required' : 'sometimes';
        // Новые учётные данные нужны при создании и при смене способа авторизации
        $needsCredential = $this->creating() || $this->authType() !== $this->target()?->auth_type;

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'host' => [$required, 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['sometimes', 'integer', 'between:1,65535'],
            'username' => [$required, 'string', 'max:255'],
            'auth_type' => [$required, Rule::in(Server::AUTH_TYPES)],
            'private_key' => [Rule::requiredIf($needsCredential && $this->authType() === Server::AUTH_KEY), 'nullable', 'string', 'max:20000'],
            'passphrase' => ['nullable', 'string', 'max:1000'],
            'password' => [Rule::requiredIf($needsCredential && $this->authType() === Server::AUTH_PASSWORD), 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Проверяем, что закрытый ключ читается (и подходит парольная фраза), — ещё до подключения к серверу.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || $this->authType() !== Server::AUTH_KEY || ! $this->filled('private_key')) {
                return;
            }
            try {
                $key = PublicKeyLoader::load($this->input('private_key'), $this->input('passphrase') ?: false);
            } catch (Throwable) {
                $validator->errors()->add('private_key', 'Не удалось прочитать закрытый ключ: проверьте ключ и парольную фразу.');

                return;
            }
            if (! $key instanceof PrivateKey) {
                $validator->errors()->add('private_key', 'Это открытый ключ, нужен закрытый.');
            }
        }];
    }

    /**
     * Данные для сохранения: учётные данные другого способа авторизации очищаются.
     *
     * @return array<string, mixed>
     */
    public function serverData(): array
    {
        $data = $this->safe()->except(['private_key', 'passphrase', 'password']);

        if ($this->authType() === Server::AUTH_KEY) {
            $data['password'] = null;
            if ($this->filled('private_key')) {
                $data['private_key'] = $this->input('private_key');
                $data['passphrase'] = $this->input('passphrase') ?: null;
            }
        } else {
            $data['private_key'] = $data['passphrase'] = null;
            if ($this->filled('password')) {
                $data['password'] = $this->input('password');
            }
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'название',
            'description' => 'описание',
            'host' => 'хост',
            'port' => 'порт',
            'username' => 'пользователь',
            'auth_type' => 'способ авторизации',
            'private_key' => 'закрытый ключ',
            'passphrase' => 'парольная фраза',
            'password' => 'пароль',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['host.regex' => 'Укажите IP-адрес или доменное имя.'];
    }
}
