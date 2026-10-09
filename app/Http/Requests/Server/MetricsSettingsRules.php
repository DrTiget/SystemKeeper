<?php

namespace App\Http\Requests\Server;

/** Правила и разворачивание вложенного объекта metrics_settings в колонки сервера. */
class MetricsSettingsRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'metrics_settings' => ['sometimes', 'array'],
            'metrics_settings.enabled' => ['sometimes', 'boolean'],
            'metrics_settings.interval_minutes' => ['sometimes', 'integer', 'between:1,1440'],
            'metrics_settings.confirmations' => ['sometimes', 'integer', 'between:1,10'],
            'metrics_settings.thresholds' => ['sometimes', 'array'],
            'metrics_settings.thresholds.disk_percent' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
            'metrics_settings.thresholds.load_per_core' => ['sometimes', 'nullable', 'numeric', 'between:0.1,100'],
            'metrics_settings.thresholds.ram_percent' => ['sometimes', 'nullable', 'integer', 'between:1,100'],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'metrics_settings' => 'настройки метрик',
            'metrics_settings.enabled' => 'сбор метрик',
            'metrics_settings.interval_minutes' => 'интервал сбора метрик',
            'metrics_settings.confirmations' => 'число подтверждений порога',
            'metrics_settings.thresholds' => 'пороги',
            'metrics_settings.thresholds.disk_percent' => 'порог диска',
            'metrics_settings.thresholds.load_per_core' => 'порог нагрузки на ядро',
            'metrics_settings.thresholds.ram_percent' => 'порог памяти',
        ];
    }

    /**
     * Переводит validated-данные в колонки Server; переданные ключи заменяют старые, остальные не трогаются.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> data без metrics_settings, но с колонками
     */
    public static function flatten(array $data): array
    {
        $settings = $data['metrics_settings'] ?? [];
        unset($data['metrics_settings']);

        $map = [
            'enabled' => 'metrics_enabled', 'interval_minutes' => 'metrics_interval_minutes', 'confirmations' => 'threshold_confirmations',
        ];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $settings)) {
                $data[$column] = $settings[$key];
            }
        }
        $thresholds = ['disk_percent' => 'threshold_disk_percent', 'load_per_core' => 'threshold_load_per_core', 'ram_percent' => 'threshold_ram_percent'];
        foreach ($thresholds as $key => $column) {
            if (array_key_exists($key, $settings['thresholds'] ?? [])) {
                $data[$column] = $settings['thresholds'][$key];
            }
        }
        // Смена интервала или включение — собрать на ближайшем запуске планировщика
        if (array_key_exists('metrics_interval_minutes', $data) || ($data['metrics_enabled'] ?? false)) {
            $data['metrics_next_at'] = null;
        }

        return $data;
    }
}
