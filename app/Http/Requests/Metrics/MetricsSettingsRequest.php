<?php

namespace App\Http\Requests\Metrics;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Настройки сбора ресурсов сервера — MetricsSettings.
 */
class MetricsSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'interval' => ['sometimes', 'integer', 'between:30,3600'],
            'retention_days' => ['sometimes', 'integer', 'between:1,365'],
            'disks_exclude' => ['sometimes', 'array', 'max:50'],
            'disks_exclude.*' => ['string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'enabled' => 'сбор',
            'interval' => 'интервал',
            'retention_days' => 'срок хранения',
            'disks_exclude' => 'исключённые диски',
            'disks_exclude.*' => 'точка монтирования',
        ];
    }
}
