<?php

namespace App\Http\Requests\SshKey;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSshKeyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('ssh_keys', 'name')->ignore($this->route('ssh_key'))],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'название'];
    }
}
