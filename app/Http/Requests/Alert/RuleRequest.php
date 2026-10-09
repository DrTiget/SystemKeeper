<?php

namespace App\Http\Requests\Alert;

use App\Models\AlertRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RuleRequest extends FormRequest
{
    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';
        $table = AlertRule::TARGETS[$this->input('target_type')] ?? null;

        return [
            'name' => [$req, 'string', 'max:255'],
            'event' => [$req, Rule::in(AlertRule::EVENTS)],
            'target_type' => ['nullable', Rule::in(array_keys(AlertRule::TARGETS))],
            'target_id' => ['nullable', 'integer', 'required_with:target_type', 'prohibited_if:target_type,null', $table ? Rule::exists($table, 'id') : 'nullable'],
            'channel_ids' => [$req, 'array', 'min:1'],
            'channel_ids.*' => ['integer', 'distinct', 'exists:alert_channels,id'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_end'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_start'],
            'throttle_minutes' => ['sometimes', 'integer', 'between:0,10080'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'название', 'event' => 'событие', 'target_type' => 'тип объекта', 'target_id' => 'объект',
            'channel_ids' => 'каналы', 'channel_ids.*' => 'канал', 'quiet_hours_start' => 'начало тихих часов',
            'quiet_hours_end' => 'конец тихих часов', 'throttle_minutes' => 'антиспам (мин)', 'enabled' => 'включено',
        ];
    }
}
