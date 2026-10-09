<?php

namespace App\Http\Requests\SshKey;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSshKeyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:ssh_keys,name'],
            'private_key' => ['nullable', 'string', 'max:20000'],
            'passphrase' => ['nullable', 'string', 'max:1000'],
            'type' => ['nullable', Rule::in(['ed25519', 'rsa'])],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'название', 'private_key' => 'закрытый ключ', 'passphrase' => 'парольная фраза', 'type' => 'тип'];
    }
}
