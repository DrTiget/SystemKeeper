<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            // Смена email требует текущего пароля
            'current_password' => ['required_with:email', 'nullable', 'string', 'current_password:sanctum'],
            'settings' => ['sometimes', 'array:'.implode(',', array_keys(User::DEFAULT_SETTINGS))],
            'settings.theme' => ['sometimes', 'string', Rule::in(User::THEMES)],
            'settings.timezone' => ['sometimes', 'string', 'timezone:all'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'имя',
            'current_password' => 'текущий пароль',
            'settings' => 'настройки',
            'settings.theme' => 'тема',
            'settings.timezone' => 'часовой пояс',
        ];
    }
}
