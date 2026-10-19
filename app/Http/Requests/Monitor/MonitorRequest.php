<?php

namespace App\Http\Requests\Monitor;

use App\Models\Monitor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Создание (POST /monitors, POST /monitors/test) и изменение (PATCH) монитора — поля MonitorInput.
 * Какие поля обязательны, зависит от типа (итоговый тип при PATCH — с учётом текущего значения).
 */
class MonitorRequest extends FormRequest
{
    private function creating(): bool
    {
        return ! $this->route('monitor');
    }

    private function type(): ?string
    {
        return $this->input('type', $this->route('monitor')?->type);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->creating() ? 'required' : 'sometimes';
        $type = $this->type();
        $need = fn (string $t) => $type === $t ? $required : 'nullable';
        $host = ['string', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9.\-:\[\]]*$/'];

        return [
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')],
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::in(Monitor::TYPES)],
            'enabled' => ['sometimes', 'boolean'],
            'url' => [$need(Monitor::HTTP), 'string', 'max:2048', 'url:http,https'],
            'hostname' => [$type !== Monitor::HTTP ? $required : 'nullable', ...$host],
            'port' => [$need(Monitor::TCP), 'integer', 'between:1,65535'],
            'method' => ['sometimes', Rule::in(Monitor::METHODS)],
            'accepted_statuses' => ['sometimes', 'array', 'min:1', 'max:20'],
            'accepted_statuses.*' => ['string', 'regex:/^[1-5]\d\d(-[1-5]\d\d)?$/'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'keyword_invert' => ['sometimes', 'boolean'],
            'headers' => ['sometimes', 'array', 'max:30'],
            'headers.*.name' => ['required', 'string', 'max:255', "regex:/^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/"],
            'headers.*.value' => ['present', 'nullable', 'string', 'max:4000', 'not_regex:/[\r\n]/'],
            'body' => ['nullable', 'string', 'max:65000'],
            'follow_redirects' => ['sometimes', 'boolean'],
            'ignore_tls' => ['sometimes', 'boolean'],
            'ssl_expiry_days' => ['nullable', 'integer', 'between:1,365'],
            'dns_record_type' => ['sometimes', Rule::in(Monitor::DNS_TYPES)],
            'dns_resolver' => ['nullable', 'ip'],
            'dns_expected' => ['nullable', 'string', 'max:1000'],
            'interval' => ['sometimes', 'integer', 'between:20,86400'],
            'timeout' => ['sometimes', 'integer', 'between:1,120'],
            'retries' => ['sometimes', 'integer', 'between:0,10'],
            'retry_interval' => ['sometimes', 'integer', 'between:20,86400'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'название', 'type' => 'тип', 'url' => 'адрес', 'hostname' => 'хост', 'port' => 'порт',
            'interval' => 'интервал', 'timeout' => 'тайм-аут', 'retries' => 'число повторов', 'retry_interval' => 'интервал повторов',
        ];
    }

    /**
     * Поля для модели: заголовки с пустыми именами отброшены.
     *
     * @return array<string, mixed>
     */
    public function monitorData(): array
    {
        $data = $this->validated();
        if (isset($data['headers'])) {
            $data['headers'] = array_values(array_map(fn ($h) => ['name' => $h['name'], 'value' => (string) ($h['value'] ?? '')], $data['headers']));
        }

        return $data;
    }
}
