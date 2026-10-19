<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class TestMailRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['to' => ['required', 'string', 'email:rfc', 'max:255']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['to' => 'адрес получателя'];
    }
}
