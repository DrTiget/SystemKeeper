<?php

namespace App\Http\Requests\Server;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServerRequest extends FormRequest
{
    public function rules(): array
    {
        $auth = $this->input('auth_type');

        return [
            'name' => ['required', 'string', 'max:255'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:255'],
            'auth_type' => ['required', Rule::in([Server::AUTH_KEY, Server::AUTH_PASSWORD])],
            'ssh_key_id' => [Rule::requiredIf($auth === Server::AUTH_KEY), 'nullable', 'integer', 'exists:ssh_keys,id'],
            'password' => [Rule::requiredIf($auth === Server::AUTH_PASSWORD), 'nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'название', 'host' => 'хост', 'port' => 'порт', 'username' => 'пользователь',
            'auth_type' => 'способ авторизации', 'ssh_key_id' => 'SSH-ключ', 'password' => 'пароль', 'description' => 'описание',
        ];
    }
}
