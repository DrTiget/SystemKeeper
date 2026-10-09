<?php

namespace App\Http\Requests\Server;

use App\Models\Server;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServerRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var Server $server */
        $server = $this->route('server');
        $auth = $this->input('auth_type', $server?->auth_type);
        $switchedToKey = $auth === Server::AUTH_KEY && $server?->auth_type !== Server::AUTH_KEY;
        $switchedToPassword = $auth === Server::AUTH_PASSWORD && $server?->auth_type !== Server::AUTH_PASSWORD;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'host' => ['sometimes', 'required', 'string', 'max:255'],
            'port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'username' => ['sometimes', 'required', 'string', 'max:255'],
            'auth_type' => ['sometimes', 'required', Rule::in([Server::AUTH_KEY, Server::AUTH_PASSWORD])],
            'ssh_key_id' => [Rule::requiredIf($switchedToKey), 'nullable', 'integer', 'exists:ssh_keys,id'],
            'password' => [Rule::requiredIf($switchedToPassword), 'nullable', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ] + MetricsSettingsRules::rules();
    }

    public function attributes(): array
    {
        return (new StoreServerRequest)->attributes();
    }
}
