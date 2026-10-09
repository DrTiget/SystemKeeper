<?php

namespace App\Http\Requests\Monitor;

use App\Models\Monitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMonitorRequest extends FormRequest
{
    /** Тип монитора: при создании из тела, при обновлении — у существующего (менять нельзя). */
    protected function type(): ?string
    {
        return $this->input('type');
    }

    public function rules(): array
    {
        $type = $this->type();
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        $target = match ($type) {
            Monitor::TYPE_HTTP, Monitor::TYPE_KEYWORD => [$required, 'string', 'max:2048', 'url:http,https'],
            Monitor::TYPE_TCP => [$required, 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-_]+:\d{1,5}$/'],
            Monitor::TYPE_PING => [$required, 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-_:]+$/'],
            Monitor::TYPE_SSL => [$required, 'string', 'max:2048', 'regex:/^(https:\/\/)?[A-Za-z0-9.\-_]+(:\d{1,5})?(\/.*)?$/'],
            default => ['prohibited'],
        };

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => $creating ? ['required', Rule::in(Monitor::TYPES)] : ['prohibited'],
            'target' => $target,
            'interval_seconds' => ['sometimes', 'integer', 'between:30,86400'],
            'timeout_seconds' => ['sometimes', 'integer', 'between:1,60'],
            'expected_status' => ['nullable', 'integer', 'between:100,599', Rule::prohibitedIf($type !== Monitor::TYPE_HTTP && $type !== Monitor::TYPE_KEYWORD)],
            'keyword' => [$type === Monitor::TYPE_KEYWORD ? ($creating ? 'required' : 'sometimes') : 'prohibited', 'string', 'max:255'],
            'ssl_expiry_days' => ['sometimes', 'integer', 'between:1,365'],
            'heartbeat_grace_seconds' => ['sometimes', 'integer', 'between:0,86400'],
            'failure_threshold' => ['sometimes', 'integer', 'between:1,100'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->input('type') === Monitor::TYPE_TCP && ! $validator->errors()->has('target')) {
                $port = (int) substr((string) strrchr((string) $this->input('target'), ':'), 1);
                if ($port < 1 || $port > 65535) {
                    $validator->errors()->add('target', 'Порт должен быть от 1 до 65535.');
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'name' => 'название', 'type' => 'тип', 'target' => 'цель', 'interval_seconds' => 'интервал',
            'timeout_seconds' => 'таймаут', 'expected_status' => 'ожидаемый код', 'keyword' => 'ключевое слово',
            'ssl_expiry_days' => 'дней до истечения SSL', 'heartbeat_grace_seconds' => 'grace heartbeat',
            'failure_threshold' => 'порог неудач', 'enabled' => 'включён',
        ];
    }
}
