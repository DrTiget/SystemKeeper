<?php

namespace App\Services\Alerts;

/**
 * Типы правил алертов: к каким объектам относятся и какой у них порог. Зеркало ALERT_KINDS из frontend/src/api/alerts.ts.
 */
class AlertKinds
{
    public const SERVER_CPU = 'server_cpu';

    public const SERVER_MEMORY = 'server_memory';

    public const SERVER_DISK = 'server_disk';

    public const SERVER_LOAD = 'server_load';

    public const SERVER_UNREACHABLE = 'server_unreachable';

    public const MONITOR_DOWN = 'monitor_down';

    public const MONITOR_SSL = 'monitor_ssl';

    public const BACKUP_FAILED = 'backup_failed';

    public const BACKUP_MISSING = 'backup_missing';

    public const STORAGE_FULL = 'storage_full';

    /** Типы объектов */
    public const OBJECT_TYPES = ['server', 'monitor', 'database', 'storage'];

    /**
     * Вид → типы объектов и границы порога (null — порога нет).
     *
     * @var array<string, array{objects: list<string>, threshold: array{min: float, max: float}|null}>
     */
    private const KINDS = [
        self::SERVER_CPU => ['objects' => ['server'], 'threshold' => ['min' => 1, 'max' => 100]],
        self::SERVER_MEMORY => ['objects' => ['server'], 'threshold' => ['min' => 1, 'max' => 100]],
        self::SERVER_DISK => ['objects' => ['server'], 'threshold' => ['min' => 1, 'max' => 100]],
        self::SERVER_LOAD => ['objects' => ['server'], 'threshold' => ['min' => 0.1, 'max' => 100]],
        self::SERVER_UNREACHABLE => ['objects' => ['server'], 'threshold' => null],
        self::MONITOR_DOWN => ['objects' => ['monitor'], 'threshold' => null],
        self::MONITOR_SSL => ['objects' => ['monitor'], 'threshold' => ['min' => 1, 'max' => 90]],
        self::BACKUP_FAILED => ['objects' => ['server', 'database'], 'threshold' => null],
        self::BACKUP_MISSING => ['objects' => ['server', 'database'], 'threshold' => ['min' => 1, 'max' => 720]],
        self::STORAGE_FULL => ['objects' => ['storage'], 'threshold' => ['min' => 1, 'max' => 100]],
    ];

    /**
     * Подписи видов для условия правила: название, фраза перед порогом, единица, имеет ли смысл «держится N минут».
     * Зеркало ALERT_KINDS из frontend/src/api/alerts.ts.
     *
     * @var array<string, array{0: string, 1: string|null, 2: string, 3: bool}>
     */
    private const LABELS = [
        self::SERVER_CPU => ['Высокая загрузка CPU', 'CPU выше', '%', true],
        self::SERVER_MEMORY => ['Мало памяти', 'Память занята больше', '%', true],
        self::SERVER_DISK => ['Заканчивается место на диске', 'Диск заполнен больше', '%', false],
        self::SERVER_LOAD => ['Высокая нагрузка (load average)', 'Load на ядро больше', '', true],
        self::SERVER_UNREACHABLE => ['Сервер не отвечает', null, '', true],
        self::MONITOR_DOWN => ['Монитор недоступен', null, '', true],
        self::MONITOR_SSL => ['Истекает сертификат', 'Осталось меньше', 'дн.', false],
        self::BACKUP_FAILED => ['Бекап не удался', null, '', false],
        self::BACKUP_MISSING => ['Давно не было бекапа', 'Нет успешного бекапа дольше', 'ч', false],
        self::STORAGE_FULL => ['Хранилище почти заполнено', 'Заполнено больше', '%', false],
    ];

    /** Условие правила одной строкой: «CPU выше 90% дольше 5 мин». Аналог describeCondition из frontend/src/api/alerts.ts. */
    public static function describe(string $kind, ?float $threshold, int $duration): string
    {
        [$label, $thresholdLabel, $unit, $hasDuration] = self::LABELS[$kind] ?? [$kind, null, '', false];

        $base = $label;
        if ($thresholdLabel !== null && $threshold !== null) {
            $value = str_replace('.', ',', (string) $threshold);
            $base = "{$thresholdLabel} {$value}".($unit === '%' ? '%' : ($unit !== '' ? " {$unit}" : ''));
        }

        return $hasDuration && $duration > 0 ? "{$base} дольше {$duration} мин" : $base;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::KINDS);
    }

    /** @return list<string> типы объектов, к которым применим вид */
    public static function objects(string $kind): array
    {
        return self::KINDS[$kind]['objects'] ?? [];
    }

    /** @return array{min: float, max: float}|null */
    public static function threshold(string $kind): ?array
    {
        return self::KINDS[$kind]['threshold'] ?? null;
    }
}
