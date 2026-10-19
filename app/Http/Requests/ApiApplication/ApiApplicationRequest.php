<?php

namespace App\Http\Requests\ApiApplication;

use App\Models\ApiApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiApplicationRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'scopes' => [$required, 'array'],
            'scopes.*' => ['string', 'distinct', Rule::in(ApiApplication::availableScopes())],
        ];
    }

    public function attributes(): array
    {
        return ['scopes' => 'права', 'scopes.*' => 'право'];
    }
}
