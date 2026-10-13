<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['sometimes', 'required', 'string', Password::min(8)],
            'is_admin' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Нельзя снять права администратора с самого себя — иначе можно остаться без администраторов.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var User $target */
            $target = $this->route('user');
            if ($this->has('is_admin') && ! $this->boolean('is_admin') && $target->is($this->user())) {
                $validator->errors()->add('is_admin', 'Нельзя снять права администратора с себя.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'имя', 'is_admin' => 'администратор'];
    }
}
