<?php

namespace App\Http\Requests\Alert;

use App\Models\AlertRule;
use App\Models\BackupStorage;
use App\Models\DatabaseConnection;
use App\Models\Monitor;
use App\Models\Server;
use App\Services\Alerts\AlertKinds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Создание (POST) и изменение (PATCH) правила — AlertRuleInput. Порог обязателен у видов, где он есть,
 * и должен лежать в границах вида; объекты в `targets` — подходящего вида и существующие.
 */
class AlertRuleRequest extends FormRequest
{
    private const MODELS = ['server' => Server::class, 'monitor' => Monitor::class, 'database' => DatabaseConnection::class, 'storage' => BackupStorage::class];

    private function rule(): ?AlertRule
    {
        return $this->route('alert_rule');
    }

    private function kind(): ?string
    {
        return $this->input('kind', $this->rule()?->kind);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->rule() ? 'sometimes' : 'required';
        $kind = $this->kind();
        $limits = $kind ? AlertKinds::threshold($kind) : null;
        $types = $kind ? AlertKinds::objects($kind) : AlertKinds::OBJECT_TYPES;

        return [
            'name' => [$required, 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'kind' => [$required, Rule::in(AlertKinds::all())],
            'severity' => [$required, Rule::in(AlertRule::SEVERITIES)],
            'threshold' => $limits
                ? [$required === 'required' || $this->has('kind') ? 'required' : 'sometimes', 'numeric', "between:{$limits['min']},{$limits['max']}"]
                : ['nullable'],
            'duration' => ['sometimes', 'nullable', 'integer', 'between:0,1440'],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')],
            'targets' => ['sometimes', 'nullable', 'array', 'max:500'],
            'targets.*.type' => ['required', Rule::in($types)],
            'targets.*.id' => ['required', 'integer', 'min:1'],
            'channel_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'channel_ids.*' => ['integer', Rule::exists('alert_channels', 'id')],
            'repeat_every' => ['sometimes', 'nullable', 'integer', 'between:5,43200'],
            'notify_resolved' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'название',
            'kind' => 'тип правила',
            'severity' => 'важность',
            'threshold' => 'порог',
            'duration' => 'длительность',
            'project_id' => 'проект',
            'targets' => 'объекты',
            'targets.*.type' => 'тип объекта',
            'targets.*.id' => 'объект',
            'channel_ids' => 'каналы',
            'channel_ids.*' => 'канал',
            'repeat_every' => 'повтор',
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            // объекты должны существовать; ошибка — на `targets`, как показывает форма
            foreach (collect($this->input('targets', []))->groupBy('type') as $type => $targets) {
                $ids = $targets->pluck('id')->unique();
                if (self::MODELS[$type]::query()->whereIn('id', $ids)->count() !== $ids->count()) {
                    $validator->errors()->add('targets', 'Некоторых выбранных объектов не существует.');
                }
            }
        }];
    }

    /**
     * Поля правила для сохранения (без каналов).
     *
     * @return array<string, mixed>
     */
    public function ruleData(): array
    {
        $data = $this->safe()->except(['channel_ids']);
        if (array_key_exists('targets', $data)) {
            $data['targets'] = collect($data['targets'] ?? [])->map(fn ($t) => ['type' => $t['type'], 'id' => (int) $t['id']])->unique(fn ($t) => $t['type'].$t['id'])->values()->all();
        }
        if (array_key_exists('duration', $data)) {
            $data['duration'] = (int) $data['duration'];
        }
        // у видов без порога его нет
        if (isset($data['kind']) && AlertKinds::threshold($data['kind']) === null) {
            $data['threshold'] = null;
        }

        return $data;
    }
}
